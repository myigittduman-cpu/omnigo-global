import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export const summaryText=({orderCount,productCount})=>i18nText`Toplam: ${orderCount} sipariş · ${productCount} parça ürün`;
export function deliverySummary(orders){
 const identities=new Set(orders.map((order,index)=>`${order.provider}:${order.orderNumber||order.externalId||order.id||index}`));
 return {orderCount:identities.size,productCount:orders.reduce((sum,order)=>sum+(order.products||[]).reduce((count,item)=>count+Math.max(0,Number(item.quantity)||0),0),0)};
}
export function shipmentSummary(data){
 return data.summary||{orderCount:data.items.length,productCount:data.items.reduce((sum,row)=>sum+Math.max(0,Number(row.quantity)||0),0)};
}
