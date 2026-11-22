export const deliverySelectionKey=order=>order.selectionKey??order.id;
export function deliverySelectionEntries(items,selected){
 const entries=new Map();
 for(const order of items){
  if(!selected.has(deliverySelectionKey(order)))continue;
  for(const record of order.selectionRecords??[order])entries.set(record.id,{id:record.id,version:record.version});
 }
 return [...entries.values()];
}
// The list supplied here contains only exportable orders.
export function selectPageDeliveries(items,checked){
 return new Set(checked?items.map(deliverySelectionKey):[]);
}
