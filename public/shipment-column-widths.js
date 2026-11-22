/** Fit content-sized columns first; share all remaining room between addresses. */
export function shipmentColumnWidths(available,columns){
 const total=Math.max(0,Math.floor(available)),addressCount=columns.filter(column=>column.address).length;
 if(!total||!addressCount)return columns.map(()=>0);
 const regular=columns.filter(column=>!column.address);
 const minimum=regular.reduce((sum,column)=>sum+column.minimum,0);
 const floor=regular.reduce((sum,column)=>sum+(column.floor??column.minimum),0);
 const preferred=regular.reduce((sum,column)=>sum+Math.max(column.minimum,column.preferred),0);
 const budget=Math.min(preferred,Math.max(floor,total*.80));
 const room=Math.max(0,Math.min(total-addressCount*32,budget));
 const ratio=preferred>minimum?Math.min(1,Math.max(0,(room-minimum)/(preferred-minimum))):0;
 const compactRatio=minimum>floor?Math.max(0,(room-floor)/(minimum-floor)):0;
 const widths=columns.map(column=>{
  if(column.address)return 0;
  const base=column.floor??column.minimum;
  return Math.floor(room<floor?base*room/floor:room<minimum?base+(column.minimum-base)*compactRatio:column.minimum+(Math.max(column.minimum,column.preferred)-column.minimum)*ratio);
 });
 let remaining=total-widths.reduce((sum,width)=>sum+width,0),left=addressCount;
 return widths.map((width,index)=>{if(!columns[index].address)return width;const share=Math.floor(remaining/left);remaining-=share;left--;return share;});
}
