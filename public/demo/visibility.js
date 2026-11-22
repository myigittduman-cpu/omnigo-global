/** Demo fixtures follow the same default presentation; no production data is accessed. */
const hiddenFields=new Set(['cancelCents','operationalCancelCents','cancelQty','cancelQuantity','cancelledQuantity','cancelledProducts','cancelled','cancellation','operationalCancellation','returnCents','returnQty','returnQuantity','returnedQuantity','returned','returnAmountVerified']);
export function demoFinancialView(value,sales=false){
 if(Array.isArray(value))return value.map(item=>demoFinancialView(item,sales));
 if(value===null||typeof value!=='object')return value;
 const result=Object.fromEntries(Object.entries(value).filter(([key])=>!hiddenFields.has(key)&&key!=='cancelOrders').map(([key,item])=>[key,demoFinancialView(item,sales)]));
 if(sales&&Number.isFinite(value.grossCents)){
  const turnover=value.grossCents-(value.cancelCents||0);
  for(const key of ['grossCents','netCents','panelSalesCents','amountCents','turnoverCents']){
   if(key in result||key==='netCents'||key==='panelSalesCents')result[key]=turnover;
  }
  if(Number.isFinite(value.orders))result.orders=value.orders-(value.cancelOrders||0);
  if(Number.isFinite(value.quantity))result.quantity=value.quantity-(value.cancelQty||0);
  if('averageCents' in result)result.averageCents=result.orders?Math.round(turnover/result.orders):0;
  result.turnoverDisplayBasis='after_cancellations';
 }
 return result;
}
