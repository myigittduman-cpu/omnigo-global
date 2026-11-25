import {observePageHeights} from './table-page-height.js';

/** Give ordinary data tables one local scroll surface. Fitted tables manage their own width. */
export function enhanceTables(root=document){
 for(const table of root.querySelectorAll('table:not([data-print-only],[data-table-fit])')){
  if(table.closest('[data-print-only],.print-document'))continue;
  table.classList.add('corporate-table');
  const columns=Math.max(1,...[...table.rows].slice(0,2).map(row=>[...row.cells].reduce((n,cell)=>n+cell.colSpan,0)));
  table.style.setProperty('--table-mobile-width',Math.max(560,Math.min(2400,columns*145))+'px');
  let viewport=table.parentElement;
  if(!viewport.matches('.corporate-table-viewport,.inventory-stock-details-wrap,[class*="scroll"],[class*="table-wrap"],[class*="table-container"]')){
   viewport=document.createElement('div');table.before(viewport);viewport.append(table);
  }
  viewport.classList.add('corporate-table-viewport');
  if(!viewport.hasAttribute('tabindex'))viewport.tabIndex=0;
  if(!viewport.hasAttribute('role'))viewport.setAttribute('role','region');
  if(!viewport.hasAttribute('aria-label'))viewport.setAttribute('aria-label','Tablo · Diğer sütunlar için sağa veya sola kaydırın');
 }
}
export function observeTables(root=document.body){
 enhanceTables(root);
 const pageHeights=observePageHeights(root);
 const observer=new MutationObserver(records=>{
  for(const record of records)for(const node of record.addedNodes){
   if(node.nodeType!==1)continue;
   if(node.matches('table,thead,tbody,tr,td,th'))enhanceTables(node.closest('table')?.parentElement||node);
   else if(node.querySelector('table'))enhanceTables(node);
  }
  pageHeights.sync();
 });
 observer.observe(root,{childList:true,subtree:true});
 return ()=>{observer.disconnect();pageHeights.disconnect();};
}
