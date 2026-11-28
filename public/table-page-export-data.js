import {exportColumnType,numericExportCell} from './table-export-values.js';

const excluded='[hidden],[aria-hidden="true"],script,style,template,input,textarea,select,svg,summary,button:not(.order-number,.inventory-price-button[data-purchase-price],.shipment-product-quantity),.inventory-actions-cell,.inventory-image-cell,[data-no-table-copy]';

function shown(element){
 return element.getClientRects().length>0&&element.ownerDocument.defaultView.getComputedStyle(element).visibility==='visible';
}

function cellText(cell){
 if(!shown(cell)||cell.matches(excluded))return '';
 const doc=cell.ownerDocument,walker=doc.createTreeWalker(cell,5);
 let value='',line=null;
 for(let node=walker.nextNode();node;node=walker.nextNode()){
  const element=node.nodeType===1?node:node.parentElement;
  if(!element||element.closest(excluded)||!shown(element))continue;
  if(node.nodeType===1&&!node.matches('.marketplace-mark > img'))continue;
  const text=(node.nodeType===3?node.textContent:' '+node.alt+' ').replace(/\s+/gu,' ');
  const next=element.closest('div,p,small,li,td,th,time');
  if(value&&line!==next&&text.trim())value+='\n';
  value+=text;
  if(text.trim())line=next;
 }
 return value.split('\n').map(line=>line.replace(/ {2,}/g,' ').trim()).join('\n');
}

function fields(rows){
 const matrix=rows.map(()=>[]);
 rows.forEach((row,index)=>{
  let column=0;
  for(const cell of row.cells){
   if(!shown(cell))continue;
   while(matrix[index][column]!==undefined)column++;
   const value=cellText(cell),last=cell.rowSpan===0?rows.length:Math.min(rows.length,index+cell.rowSpan);
   for(let i=index;i<last;i++)for(let j=0;j<cell.colSpan;j++)matrix[i][column+j]=j===0?value:'';
   column+=cell.colSpan;
  }
 });
 return matrix;
}

export function topLevelPageTables(main){
 return [...main.querySelectorAll('table')].filter(table=>table.tHead&&table.tBodies.length&&!table.parentElement.closest('table,dialog,[role="dialog"]')&&shown(table));
}

export function pageExportTables(tables,title,{numeric=false}={}){
 return tables.flatMap((table,index)=>{
  const headers=fields([...table.tHead.rows]),body=[...table.tBodies].flatMap(section=>[...section.rows]).filter(shown);
  const heading=headers.at(-1)||[];
  if(!heading.length)return [];
  const bodyRows=body.filter(row=>heading.length===1||row.cells.length!==1||row.cells[0].colSpan<heading.length);
  const rows=fields(bodyRows);
  const receivedRows=bodyRows.flatMap((row,index)=>row.matches('[data-payment-received],.payment-approved-row')||row.querySelector('.payment-approved-check')?[index]:[]);
  const columns=heading.map((label,column)=>({label,column})).filter(({label,column})=>label&&!/^(seç|işlem(?:ler)?|görsel|fotoğraf)$/u.test(label.toLocaleLowerCase('tr-TR'))||rows.some(row=>row[column]));
  if(!columns.length||!rows.some(row=>row.some(Boolean)))return [];
  const source=table.caption?.innerText.trim()||table.getAttribute('aria-label')||title;
  const suffix=tables.length>1?' '+(index+1):'';
  const name=(source.replace(/[\[\]:*?/\\]/g,' ').replace(/^'+|'+$/g,'').trim()||title).slice(0,31-suffix.length)+suffix;
  const types=columns.map(({label})=>numeric?exportColumnType(label):null);
  const cells=rows.map(row=>columns.map(({column},index)=>numericExportCell(row[column]??'',types[index],table.dataset.exportLocale)));
  return [{name,head:columns.map(({label},i)=>label||String(i+1)),rows:cells.map(row=>row.map(cell=>cell.value)),
   money:types.flatMap((type,index)=>type==='money'?[index]:[]),integers:types.flatMap((type,index)=>type==='integer'?[index]:[]),
   cellNotes:cells.map(row=>row.map(cell=>cell.note||'')),...(receivedRows.length?{receivedRows}:{})}];
 });
}
