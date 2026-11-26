const clipping=/^(auto|scroll|hidden|clip|overlay)$/;
const scrollable=/^(auto|scroll|overlay)$/;
const headingSelector='table > thead';
const textLabel='portal-table-head-label';

function clippingParents(head,doc,view){
 const parents=[];
 for(let el=head.parentElement?.parentElement;el&&el!==doc.body;el=el.parentElement){
  const style=view.getComputedStyle(el);
  if(style.display!=='contents'&&clipping.test(style.overflowY))parents.push({el,overflow:style.overflowY});
 }
 return parents;
}

// Scale the label visually without changing the table's row height or column widths.
function wrapLabels(head,doc,view){
 for(const cell of head.querySelectorAll(':scope > tr > th')){
  const walker=doc.createTreeWalker(cell,4),nodes=[];
  for(let node=walker.nextNode();node;node=walker.nextNode()){
   if(node.textContent.trim()&&!node.parentElement.closest('button,select,svg,[hidden],[aria-hidden="true"],.'+textLabel))nodes.push(node);
  }
  for(const node of nodes){
   const label=doc.createElement('span');
   label.className=textLabel;
   const style=view.getComputedStyle(node.parentElement),size=parseFloat(style.fontSize);
   if(size>0)label.style.setProperty('--portal-table-head-scale',String((size+1)/size));
   label.style.transformOrigin=style.textAlign==='center'?'center':style.textAlign==='right'?'right center':'left center';
   node.before(label);label.append(node);
  }
 }
}

function clear(item){
 const {head}=item;
 head.removeAttribute('data-portal-table-head');
 head.removeAttribute('data-portal-table-head-native');
 head.removeAttribute('data-portal-table-head-pinned');
 head.style.removeProperty('--portal-table-head-offset');
 head.style.removeProperty('--portal-table-head-native-top');
 head.querySelectorAll('.'+textLabel).forEach(label=>label.replaceWith(...label.childNodes));
}

export function observeTableHeadings(root){
 const doc=root.ownerDocument,view=doc?.defaultView;
 if(!view?.ResizeObserver||!view.IntersectionObserver||!view.MutationObserver)return ()=>{};
 let scheduled=0,dirty=true;
 const known=new Map(),visible=new Set();
 const schedule=()=>{if(!scheduled)scheduled=view.requestAnimationFrame(update);};
 const intersection=new view.IntersectionObserver(entries=>{
  for(const entry of entries){
   const item=known.get(entry.target);
   if(!item)continue;
   if(entry.isIntersecting)visible.add(item);else visible.delete(item);
  }
  schedule();
 });
 const resize=new view.ResizeObserver(()=>{dirty=true;schedule();});
 const containsHeading=node=>node.nodeType===1&&(node.matches('table,thead')||node.querySelector(headingSelector));
 const mutations=new view.MutationObserver(records=>{
  if(!records.some(record=>[...record.addedNodes,...record.removedNodes].some(containsHeading)))return;
  dirty=true;schedule();
 });

 function sync(){
  const next=new Set();
  for(const head of root.querySelectorAll(headingSelector)){
   const table=head.parentElement;
   if(!head.rows.length)continue;
   next.add(table);
   let item=known.get(table);
   if(!item||item.head!==head){
    const wasVisible=visible.delete(item);
    if(item)clear(item);
    item={head,table,shift:0,native:false,top:0};
    if(wasVisible)visible.add(item);
    known.set(table,item);
    head.setAttribute('data-portal-table-head','');
    wrapLabels(head,doc,view);
    item.headingOffset=head.getBoundingClientRect().top-table.getBoundingClientRect().top;
    intersection.observe(table);resize.observe(table);
   }
   item.parents=clippingParents(head,doc,view);
   item.header=(table.closest('dialog')||table.closest('.workspace'))?.querySelector('.dialog-head,.workspace-navigation-header,.admin-navigation-header');
  }
  for(const [table,item] of known)if(!next.has(table)){
   intersection.unobserve(table);resize.unobserve(table);visible.delete(item);known.delete(table);
   clear(item);
  }
 }

 function measure(item){
  const {head,table,parents,header}=item;
  const rect=head.getBoundingClientRect(),tableRect=table.getBoundingClientRect();
  const base=tableRect.top+item.headingOffset;
  let top=0,bottom=view.innerHeight;
  for(const {el} of parents){
   const y=el.getBoundingClientRect().top+el.clientTop;
   top=Math.max(top,y);
   bottom=Math.min(bottom,y+el.clientHeight);
  }
  const container=parents[0];
  // Native sticky is tied to the nearest scroll container, including horizontal-only wrappers.
  const native=!container||(parents.length===1&&scrollable.test(container.overflow)&&container.el.scrollHeight>container.el.clientHeight+1);
  const origin=container?container.el.getBoundingClientRect().top+container.el.clientTop:0;
  if(header&&['fixed','sticky'].includes(view.getComputedStyle(header).position)){
   const edge=header.getBoundingClientRect();
   if(edge.top<=top+1&&edge.bottom>top)top=edge.bottom;
  }
  const shift=rect.height&&top<bottom?Math.max(0,Math.min(top,tableRect.bottom-rect.height)-base):0;
  return {shift:shift>.5?shift:0,native,top:Math.max(0,top-origin)};
 }

 function update(){
  scheduled=0;
  if(dirty){dirty=false;sync();}
  const positions=[...visible].map(item=>[item,measure(item)]);
  for(const [item,{shift,native,top}] of positions){
   if(item.native===native&&item.shift===shift&&item.top===top)continue;
   if(item.native!==native)item.head.toggleAttribute('data-portal-table-head-native',native);
   if(Boolean(item.shift)!==Boolean(shift))item.head.toggleAttribute('data-portal-table-head-pinned',shift>0);
   if(native&&(item.top!==top||!item.native))item.head.style.setProperty('--portal-table-head-native-top',top+'px');
   if(!native&&item.native)item.head.style.removeProperty('--portal-table-head-native-top');
   if(!native&&shift>0)item.head.style.setProperty('--portal-table-head-offset',shift+'px');
   else if(!item.native&&item.shift>0)item.head.style.removeProperty('--portal-table-head-offset');
   Object.assign(item,{shift,native,top});
  }
 }
 mutations.observe(root,{childList:true,subtree:true});
 view.addEventListener('scroll',schedule,{capture:true,passive:true});
 const onResize=()=>{dirty=true;schedule();};
 view.addEventListener('resize',onResize,{passive:true});
 schedule();
 return ()=>{
  view.cancelAnimationFrame(scheduled);
  view.removeEventListener('scroll',schedule,true);
  view.removeEventListener('resize',onResize);
  mutations.disconnect();resize.disconnect();intersection.disconnect();
  for(const item of known.values())clear(item);
 };
}
