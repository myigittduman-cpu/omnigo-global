const clipping=/^(auto|scroll|hidden|clip|overlay)$/;
const headingSelector='table > thead';
const headingFontSize='--portal-table-head-font-size';

function clippingParents(head,doc,view){
 const parents=[];
 for(let el=head.parentElement?.parentElement;el&&el!==doc.body;el=el.parentElement){
  const style=view.getComputedStyle(el);
  if(style.display!=='contents'&&clipping.test(style.overflowY))parents.push(el);
 }
 return parents;
}

/** Keep real header cells, including selection controls, in the same table and horizontal scroll lane. */
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
    item={head,table,shift:0};
    if(wasVisible)visible.add(item);
    known.set(table,item);
    head.setAttribute('data-portal-table-head','');
    intersection.observe(table);resize.observe(table);
   }
   item.parents=clippingParents(head,doc,view);
   item.header=(table.closest('dialog')||table.closest('.workspace'))?.querySelector('.dialog-head,.workspace-navigation-header,.admin-navigation-header');
  }
  for(const [table,item] of known)if(!next.has(table)){
   intersection.unobserve(table);resize.unobserve(table);visible.delete(item);known.delete(table);
  }
 }
 function measure(item){
  const {head,table,parents,header}=item;
  const rect=head.getBoundingClientRect();
  if(!rect.height)return 0;
  const base=rect.top-item.shift;
  let top=0,bottom=view.innerHeight;
  for(const parent of parents){
   const y=parent.getBoundingClientRect().top+parent.clientTop;
   top=Math.max(top,y);
   bottom=Math.min(bottom,y+parent.clientHeight);
  }
  if(header&&['fixed','sticky'].includes(view.getComputedStyle(header).position)){
   const edge=header.getBoundingClientRect();
   if(edge.top<=top+1&&edge.bottom>top)top=edge.bottom;
  }
  if(top>=bottom)return 0;
  return Math.max(0,Math.min(top,table.getBoundingClientRect().bottom-rect.height)-base);
 }
 function clearHeadingFontSizes(head){
  for(const cell of head.querySelectorAll(':scope > tr > th'))cell.style.removeProperty(headingFontSize);
 }
 function update(){
  scheduled=0;
  if(dirty){dirty=false;sync();}
  const positions=[...visible].map(item=>{
   const shift=measure(item);
   const fontSizes=shift>0&&item.shift===0
    ? [...item.head.querySelectorAll(':scope > tr > th')].map(cell=>[cell,view.getComputedStyle(cell).fontSize])
    : [];
   return {item,shift,fontSizes};
  });
  for(const {item,shift,fontSizes} of positions){
   if(item.shift===shift)continue;
   for(const [cell,fontSize] of fontSizes)cell.style.setProperty(headingFontSize,fontSize);
   if(shift===0)clearHeadingFontSizes(item.head);
   item.shift=shift;
   item.head.toggleAttribute('data-portal-table-head-pinned',shift>0);
   if(shift>0)item.head.style.setProperty('--portal-table-head-offset',shift+'px');
   else item.head.style.removeProperty('--portal-table-head-offset');
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
  for(const {head} of known.values()){
   head.removeAttribute('data-portal-table-head');
   head.removeAttribute('data-portal-table-head-pinned');
   head.style.removeProperty('--portal-table-head-offset');
   clearHeadingFontSizes(head);
  }
 };
}
