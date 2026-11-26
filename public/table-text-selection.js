const selectionPages='[data-file-center],[data-orders],[data-shipments],[data-returns],[data-inventory],[data-city-products],[data-reporting="shipmentInvoices"],[data-reporting="financeProfitability"]';

export function copyableTableFor(node){
 const element=node?.nodeType===1?node:node?.parentElement;
 const table=element?.closest('table');
 return table&&(table.hasAttribute('data-copyable-table')||table.closest(selectionPages))?table:null;
}

export function hasTableTextSelection(root){
 const selection=root.ownerDocument?.getSelection();
 if(!selection||selection.isCollapsed||!selection.rangeCount)return false;
 const table=copyableTableFor(selection.anchorNode);
 if(!table||!root.contains(table))return false;
 for(let index=0;index<selection.rangeCount;index++){
  const range=selection.getRangeAt(index);
  if(copyableTableFor(range.startContainer)!==table||copyableTableFor(range.endContainer)!==table)return false;
 }
 return true;
}
