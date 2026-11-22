import {shipmentRowStatus} from './shipment-status.js';

const fullLineFlags = new Set([false,0,'0','false']);

// Also handle demo data and cached responses produced before the server projected active quantities.
export function activeShipmentExport(data){
 const items=[];
 let changed=false;
 for(const row of data.items){
  const cancel=row.marketplaceCancellation||{},refund=row.marketplaceReturn||{};
  const cancelled=Number(cancel.cancelledQuantity)||0,returned=Number(refund.returnedQuantity)||0;
  const returnedStatus=row.fulfillmentStatus==='returned'&&(!row.marketplaceReturn||returned>0);
  if(row.cancelled||row.rowStatus==='cancelled'||shipmentRowStatus(row)==='cancelled'||row.fulfillmentStatus==='cancelled'||returnedStatus
   ||(cancelled>0&&fullLineFlags.has(cancel.partial))||(returned>0&&fullLineFlags.has(refund.partial))){changed=true;continue;}
  if(!cancelled&&!returned){items.push(row);continue;}
  changed=true;
  const original=Number(cancel.quantity??refund.quantity??row.quantity);
  const quantity=Math.min(Number(row.quantity??original),original-cancelled-returned,refund.remainingQuantity??Infinity);
  if(Number.isInteger(quantity)&&quantity>0)items.push({...row,quantity,invoiceCents:null,marketplaceCancellation:null,marketplaceReturn:null});
 }
 if(!changed)return data;
 const orders=new Set(items.map((row,index)=>JSON.stringify([row.provider,row.orderNumber||row.id||index])));
 return {...data,items,total:items.length,summary:{orderCount:orders.size,productCount:items.reduce((sum,row)=>sum+(Number(row.quantity)||0),0)}};
}
