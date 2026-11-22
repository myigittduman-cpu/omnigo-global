import {revealContent} from './motion.js';
const installed=new WeakSet();
const surfaces='dialog,.notification-panel,[role="tabpanel"],.delivery-city-options,.shipment-picker-panel,.permission-batch-options';
const visible=node=>node.isConnected&&!node.hidden&&!node.closest('[hidden]')&&(!node.matches('dialog')||node.open);
/** Only explicit surface changes animate. Live rows, clocks and data updates do not. */
export function installSurfaceMotion(doc=globalThis.document){
 if(!doc?.documentElement||installed.has(doc))return;
 installed.add(doc);
 const Observer=doc.defaultView?.MutationObserver;if(!Observer)return;
 const enter=node=>{if(node.matches?.(surfaces)&&visible(node))revealContent(node);};
 new Observer(records=>{
  const pending=new Set();
  for(const record of records){
   if(record.type==='attributes'){
    if(record.oldValue===record.target.getAttribute(record.attributeName))continue;
    if(['open','hidden'].includes(record.attributeName))pending.add(record.target);
    if(record.attributeName==='aria-selected'&&record.target.getAttribute('aria-selected')==='true'){
     const id=record.target.getAttribute('aria-controls');if(id)pending.add(doc.getElementById(id));
    }
   }else for(const node of record.addedNodes)if(node.nodeType===1){
    if(node.matches(surfaces))pending.add(node);
    for(const child of node.querySelectorAll(surfaces))pending.add(child);
   }
  }
  for(const node of pending){
   if(!node)continue;
   let ancestor=node.parentElement?.closest(surfaces);
   while(ancestor&&!pending.has(ancestor))ancestor=ancestor.parentElement?.closest(surfaces);
   if(!ancestor)enter(node);
  }
 }).observe(doc.documentElement,{subtree:true,childList:true,attributes:true,attributeOldValue:true,attributeFilter:['open','hidden','aria-selected']});
 // Details-based pickers retain native keyboard and dismissal behavior.
 doc.addEventListener('toggle',event=>{if(event.target.matches?.('details[open]'))for(const node of event.target.children)if(node.tagName!=='SUMMARY')revealContent(node);},true);
}
installSurfaceMotion();
