import {pageControlSelector,pageTableForControl} from './table-page-targets.js';

const controllers=new WeakMap();
const heightProperty='--pagination-frame-height';
const contextButton=[
 '[role="tab"]','[aria-pressed]','[data-page-reset]','[data-nav]','[data-products-city]','[data-file-center-view]',
 '[data-tracking-bucket]','[data-tracking-clear]','[data-shipment-filter-reset]','[data-shipment-clear]',
 '[data-shipment-provider]','[data-shipment-month]','[data-city]','[data-delivery-queue]','[data-city-clear]',
 '[data-inbox="status"]','[data-inbox="provider"]','[data-range]','[data-history-clear]','[data-analysis-period]',
 '[data-attention-type]','[data-payment-list-tab]','[data-directory-tab]','[data-upcoming-period-picker]','[data-upcoming-period-clear]'
].join(',');

function rowCount(frame){
 if(frame?.matches?.('.delivery-orders,.support-grid'))return frame.childElementCount;
 const bodies=frame?.querySelector?.('table')?.tBodies;
 return bodies?Array.from(bodies).reduce((count,body)=>count+body.rows.length,0):0;
}

/** Restore a replaced table viewport before existing scroll/focus restoration measures the new page. */
export function syncTablePageHeights(doc){
 controllers.get(doc)?.sync();
}

export function observePageHeights(root){
 const doc=root.ownerDocument,view=doc.defaultView;
 const entries=new Set();
 function release(entry){
  entry.frame?.style.removeProperty(heightProperty);
  entry.frame?.removeAttribute('data-page-height-locked');
  entries.delete(entry);
 }
 function sync(){
  for(const entry of entries){
   if(!entry.scope.isConnected){release(entry);continue;}
   const frame=entry.frame?.isConnected?entry.frame:entry.resolve();
   if(!frame)continue;
   if(frame!==entry.frame||!frame.hasAttribute('data-page-height-locked')){
    entry.frame=frame;
    frame.style.setProperty(heightProperty,entry.height+'px');
    frame.setAttribute('data-page-height-locked','');
   }
  }
 }
 function resetFor(target){
  for(const entry of entries){
   if(entry.scope.contains(target)&&!entry.frame?.contains(target)&&
     target.closest('dialog')===entry.scope.closest('dialog'))release(entry);
  }
 }
 function click(event){
  const target=event.target;
  if(!target?.closest)return;
  const control=target.closest(pageControlSelector);
  if(control?.matches(':disabled,[aria-disabled="true"],[aria-current="page"]'))return;
  const table=control&&pageTableForControl(control);
  if(!table){
   if(target.closest(contextButton)){
    if(target.closest('[data-nav]')&&!target.closest('dialog'))clear();else resetFor(target);
   }
   return;
  }
  for(const entry of entries)if(entry.scope===table.scope&&entry.key===table.key)return;
  const height=table.frame.getBoundingClientRect().height;
  if(height<=0)return;
  entries.add({...table,height,referenceRows:rowCount(table.frame)});
  sync();
 }
 function change(event){
  if(event.target.matches?.('select,input[type="search"],input[type="date"]'))resetFor(event.target);
 }
 function search(event){
  if(event.target.matches?.('input[type="search"],input[name="query"],input[data-activity-query]'))resetFor(event.target);
 }
 const submit=event=>resetFor(event.target);
 const close=event=>{
  for(const entry of entries)if(event.target.contains(entry.scope))release(entry);
 };
 function clear(){for(const entry of entries)release(entry);}
 let width=view.innerWidth,height=view.innerHeight;
 function resize(){
  // On mobile the address bar can resize the visual viewport during ordinary scrolling.
  const nextWidth=view.innerWidth,nextHeight=view.innerHeight;
  for(const entry of entries){
   if(entry.scope.closest('dialog')){
    if(width!==nextWidth||height!==nextHeight)release(entry);
    continue;
   }
   if(width===nextWidth)continue;
   if(!entry.referenceRows){release(entry);continue;}
   const frame=entry.frame;
   if(!frame?.isConnected||rowCount(frame)<entry.referenceRows)continue;
   // A partial last page cannot tell us the height of a full page at the new width.
   // Re-measure only a populated page. A shorter page keeps the last usable full frame.
   frame.removeAttribute('data-page-height-locked');
   const natural=frame.getBoundingClientRect().height;
   if(natural>0)entry.height=natural;
  }
  sync();
  width=nextWidth;height=nextHeight;
 }
 const controller={sync};
 controllers.set(doc,controller);
 root.addEventListener('click',click,true);
 root.addEventListener('submit',submit,true);
 root.addEventListener('change',change,true);
 root.addEventListener('input',search,true);
 root.addEventListener('close',close,true);
 view.addEventListener('resize',resize);
 return {
  sync,
  disconnect(){
   clear();
   controllers.delete(doc);
   root.removeEventListener('click',click,true);
   root.removeEventListener('submit',submit,true);
   root.removeEventListener('change',change,true);
   root.removeEventListener('input',search,true);
   root.removeEventListener('close',close,true);
   view.removeEventListener('resize',resize);
  }
 };
}
