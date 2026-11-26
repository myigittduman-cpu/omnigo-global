const selectionPages='[data-file-center],[data-orders],[data-shipments],[data-returns],[data-inventory],[data-city-products],[data-reporting="shipmentInvoices"],[data-reporting="financeProfitability"]';
const dataButton='.order-number,.shipment-product-quantity';
export const tableTextExclusions=[
 'thead','tfoot','th','nav','[role="columnheader"]','[hidden]','[aria-hidden="true"]','img','picture','svg',
 '.marketplace-mark','.product-marketplaces','.inventory-image-cell','.inventory-stock-cell','.inventory-price-cell','.inventory-actions-cell',
 'input','textarea','select','summary','[data-no-table-copy]',
 '[role="button"]:not(:is('+dataButton+'))','button:not(:is('+dataButton+'))'
].join(',');

function elementFor(node){return node?.nodeType===1?node:node?.parentElement;}

export function copyableTableFor(node){
 const table=elementFor(node)?.closest('table');
 return table&&(table.hasAttribute('data-copyable-table')||table.closest(selectionPages))?table:null;
}

export function isTableTextTarget(node){
 const element=elementFor(node);
 return Boolean(copyableTableFor(node)&&element?.closest('tbody td')&&!element.closest(tableTextExclusions));
}

function selectedTable(root,selection){
 if(!selection||selection.isCollapsed||!selection.rangeCount)return null;
 const table=copyableTableFor(selection.anchorNode);
 if(!table||!root.contains(table))return null;
 for(let index=0;index<selection.rangeCount;index++){
  const range=selection.getRangeAt(index);
  if(copyableTableFor(range.startContainer)!==table||copyableTableFor(range.endContainer)!==table)return null;
 }
 return table;
}

export function hasTableTextSelection(root){
 return Boolean(selectedTable(root,root.ownerDocument?.getSelection()));
}

export function tableSelectionText(root){
 const doc=root.ownerDocument,selection=doc?.getSelection(),table=selectedTable(root,selection);
 if(!table)return '';
 const rows=[];
 for(const body of table.tBodies)for(const row of body.rows){
  const values=[];
  for(const cell of row.cells){
   if(!isTableTextTarget(cell))continue;
   const pieces=[];
   for(let index=0;index<selection.rangeCount;index++){
    const range=selection.getRangeAt(index);
    if(!range.intersectsNode(cell))continue;
    const walker=doc.createTreeWalker(cell,4);
    let previousLine=null,value='';
    for(let node=walker.nextNode();node;node=walker.nextNode()){
     if(!range.intersectsNode(node)||!isTableTextTarget(node)||!node.parentElement.getClientRects().length
      ||doc.defaultView.getComputedStyle(node.parentElement).visibility!=='visible')continue;
     const start=range.startContainer===node?range.startOffset:0,end=range.endContainer===node?range.endOffset:node.length;
     const fragment=node.data.slice(start,end).replace(/\s+/gu,' ');
     const line=node.parentElement.closest('div,p,small,li,td');
     if(value&&line!==previousLine&&fragment.trim())value+='\n';
     value+=fragment;
     if(fragment.trim())previousLine=line;
    }
    if(value.trim())pieces.push(value.split('\n').map(line=>line.replace(/ {2,}/g,' ').trim()).join('\n'));
   }
   if(pieces.length)values.push(pieces.join('\n'));
  }
  if(values.length)rows.push(values.join('\t'));
 }
 return rows.join('\n');
}
