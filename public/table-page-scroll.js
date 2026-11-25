import {syncTablePageHeights} from './table-page-height.js';

// Scroll only the vertical viewport owning the table; preserve mobile horizontal position.
export function preserveTablePosition(root,viewport=globalThis.window){
 // The browser's delayed scroll anchoring must not undo the explicit restoration.
 if(root.style)root.style.overflowAnchor='none';
 const positions=[];
 for(let node=root;node;node=node.parentElement)positions.push([node,node.scrollTop,node.scrollLeft]);
 const selector='[class*="scroll"],.corporate-table-viewport,.return-table-wrap,.orders-table-wrap';
 const local=[...(root.querySelectorAll?.(selector)||[])]
  .map((node,index)=>[index,node.scrollTop,node.scrollLeft,node.className]).filter(([,top,left])=>top||left);
 const x=viewport?.scrollX,y=viewport?.scrollY;
 return ()=>{
  syncTablePageHeights(root.ownerDocument);
  for(const [node,top,left] of positions){
   if(node.scrollTop!==top)node.scrollTop=top;
   if(node.scrollLeft!==left)node.scrollLeft=left;
  }
  if(local.length){
   const nodes=[...root.querySelectorAll(selector)];
   for(const [index,top,left,name] of local){
    const node=nodes[index];
    if(node?.className!==name)continue;
    if(!node)continue;
    if(node.scrollTop!==top)node.scrollTop=top;
    if(node.scrollLeft!==left)node.scrollLeft=left;
   }
  }
  if(viewport&&(viewport.scrollX!==x||viewport.scrollY!==y)){
   viewport.scrollTo({left:x,top:y,behavior:'instant'});
  }
 };
}
export function scrollTableStart(root, selector = 'table', viewport = globalThis.window) {
 if (!root?.isConnected || !viewport) return;
 syncTablePageHeights(root.ownerDocument);
 const table = root.querySelector(selector);
 if (!table) return;
 const document=root.ownerDocument,body=document?.body,dialog=table.closest?.('dialog[open]');
 const scrollable=node=>/(auto|scroll|overlay)/.test(viewport.getComputedStyle(node).overflowY)&&node.scrollHeight>node.clientHeight+1;
 const header=dialog?.querySelector('.dialog-head')||root.closest?.('.workspace')?.querySelector('.workspace-navigation-header,.admin-navigation-header');
 const offset=(origin,container)=>header&&(!container||container.contains?.(header))&&['sticky','fixed'].includes(viewport.getComputedStyle(header).position)?Math.max(0,header.getBoundingClientRect().bottom-origin):0;
 // A bounded table viewport must return to its first row as well as the outer page.
 if(scrollable(table))table.scrollTo({top:0,behavior:'instant'});
 let anchor=table;
 for(let container=table.parentElement;container&&container!==body;container=container.parentElement){
  if(scrollable(container)){
   const origin=container.getBoundingClientRect().top+(container.clientTop||0);
   const top=Math.max(0,container.scrollTop+anchor.getBoundingClientRect().top-origin-offset(origin,container)-12);
   container.scrollTo({top,behavior:'instant'});
   anchor=container;
  }
  if(container===dialog)return; // A dialog never scrolls the page behind it.
 }
 if(dialog)return;
 const top=Math.max(0,viewport.scrollY+anchor.getBoundingClientRect().top-offset(0)-12);
 // The browser clamps the scroll target when the remaining content is short.
 viewport.scrollTo({top,behavior:'instant'});
}
