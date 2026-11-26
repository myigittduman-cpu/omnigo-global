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
  const text=(node.nodeType===3?node.textContent:node.alt).trim();
  if(!text)continue;
  const next=element.closest('div,p,small,li,td,th');
  value+=(value?(line===next?' ':'\n'):'')+text;
  line=next;
 }
 return value;
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

export function pageExportTables(tables,title){
 return tables.flatMap((table,index)=>{
  const headers=fields([...table.tHead.rows]),body=[...table.tBodies].flatMap(section=>[...section.rows]).filter(shown);
  const heading=headers.at(-1)||[];
  if(!heading.length)return [];
  const rows=fields(body.filter(row=>heading.length===1||row.cells.length!==1||row.cells[0].colSpan<heading.length));
  const columns=heading.map((label,column)=>({label,column})).filter(({label,column})=>label&&!/^(seç|işlem(?:ler)?|görsel|fotoğraf)$/u.test(label.toLocaleLowerCase('tr-TR'))||rows.some(row=>row[column]));
  if(!columns.length||!rows.some(row=>row.some(Boolean)))return [];
  const source=table.caption?.innerText.trim()||table.getAttribute('aria-label')||title;
  const suffix=tables.length>1?' '+(index+1):'';
  const name=(source.replace(/[\[\]:*?/\\]/g,' ').replace(/^'+|'+$/g,'').trim()||title).slice(0,31-suffix.length)+suffix;
  return [{name,head:columns.map(({label},i)=>label||String(i+1)),rows:rows.map(row=>columns.map(({column})=>row[column]||'')),money:[]}];
 });
}
