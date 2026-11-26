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

function selectedAnchorCell(root,selection){
 if(!selection||selection.isCollapsed||!selection.rangeCount||!isTableTextTarget(selection.anchorNode))return null;
 const cell=elementFor(selection.anchorNode).closest('tbody td');
 return root.contains(cell)?cell:null;
}

function inCell(node,cell){
 return elementFor(node)?.closest('td,th')===cell;
}

function selectedCell(root,selection){
 const cell=selectedAnchorCell(root,selection);
 if(!cell||selection.rangeCount!==1)return null;
 const range=selection.getRangeAt(0);
 return inCell(range.startContainer,cell)&&inCell(range.endContainer,cell)?cell:null;
}

export function constrainTableSelection(root){
 const doc=root.ownerDocument,selection=doc?.getSelection(),cell=selectedAnchorCell(root,selection);
 if(!cell||selectedCell(root,selection))return;
 const bounds=doc.createRange();
 bounds.selectNodeContents(cell);
 const {anchorNode,anchorOffset,focusNode,focusOffset}=selection;
 const edge=bounds.comparePoint(focusNode,focusOffset)<0?0:cell.childNodes.length;
 selection.setBaseAndExtent(anchorNode,anchorOffset,cell,edge);
}

export function hasTableTextSelection(root){
 return Boolean(selectedCell(root,root.ownerDocument?.getSelection()));
}

export function tableSelectionText(root){
 const doc=root.ownerDocument,selection=doc?.getSelection(),cell=selectedCell(root,selection);
 if(!cell)return '';
 const range=selection.getRangeAt(0),walker=doc.createTreeWalker(cell,4);
 let previousLine=null,value='';
 for(let node=walker.nextNode();node;node=walker.nextNode()){
  if(!range.intersectsNode(node)||!inCell(node,cell)||!isTableTextTarget(node)||!node.parentElement.getClientRects().length
   ||doc.defaultView.getComputedStyle(node.parentElement).visibility!=='visible')continue;
  const start=range.startContainer===node?range.startOffset:0,end=range.endContainer===node?range.endOffset:node.length;
  const fragment=node.data.slice(start,end).replace(/\s+/gu,' ');
  const line=node.parentElement.closest('div,p,small,li,td');
  if(value&&line!==previousLine&&fragment.trim())value+='\n';
  value+=fragment;
  if(fragment.trim())previousLine=line;
 }
 return value.split('\n').map(line=>line.replace(/ {2,}/g,' ').trim()).join('\n');
}
