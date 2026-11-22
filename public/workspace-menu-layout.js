/** Presentation only: place the visible links in at most two rows. */
export function workspaceMenuLayout(count){
 if(!Number.isSafeInteger(count)||count<0)throw new RangeError('Geçersiz menü sayısı.');
 const columns=Math.max(1,Math.ceil(count/2)),units=columns*2;
 const slots=Array.from({length:count},(_,index)=>{
  if(count===1)return {row:1,rowSpan:2,column:1,span:units};
  const row=index<columns?1:2,offset=row===1?index:index-columns;
  const rowCount=row===1?columns:count-columns;
  return {row,rowSpan:1,column:columns-rowCount+offset*2+1,span:2};
 });
 return {columns,units,slots};
}
