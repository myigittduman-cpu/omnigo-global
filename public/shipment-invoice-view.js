import {t,text,html,labelRows,currentLocale} from './i18n.js';
import {esc,money} from './ui.js';
import {shipmentCopyButton} from './shipment-copy.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';
import {shipmentStatusLabel} from './shipment-status.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {cancellationState} from './order-cancellation.js';
import {shipmentPaymentDetails} from './shipment-payment-details.js';
import {shipmentSkuLines} from './shipment-sku-lines.js';
import {shipmentAddressKey,shipmentDeliveryAddressText,shipmentDeliveryLocation} from './shipment-address-display.js';

export const shipmentInvoiceColumns=labelRows([
 ['orderIdentity','Sipariş no / Tarih ve saat'],['billingName','Alıcı adı / Fatura adı'],
 ['billingContact','E-posta / Telefon'],['productCode','SKU/Adet'],['billingAddress','Fatura / Teslimat adresi'],
 ['billingTax','Fatura türü / Vergi'],['billingAmount','Fatura tutarı'],
]);

const present=value=>String(value??'').trim()!=='';
function field(key,label,value,{name=false,primary=false,formatted,copyValue}={}){
 const content=formatted??esc(present(value)?value:'—');
 return html`<div class="shipment-grouped-field ${primary?'shipment-grouped-primary':''}"><span class="shipment-grouped-value">${name?html`<small class="shipment-grouped-secondary">${t(label)}</small>`:''}${content}</span>${present(value)?shipmentCopyButton(key,t(label),{inline:true,value:copyValue}):''}</div>`;
}
function cell(key,content){
 return html`<td class="shipment-cell-${key}"><div class="shipment-grouped-stack">${content}</div></td>`;
}
function billingNameField(key,label,value,{primary=false}={}){
 return html`<div><small class="shipment-grouped-secondary">${t(label)}</small>${field(key,label,value,{primary})}</div>`;
}
function orderCell(row,editActions){
 const date=new Date(row.orderedAt??'');
 const format={timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'};
 const time=Number.isFinite(date.getTime())?html`<time datetime="${date.toISOString()}">${esc(date.toLocaleString(currentLocale(),format))}</time>`:undefined;
 const cancelled=cancellationState(row);
 const partial=cancelled?.partial&&cancelled.cancelledQuantity>0?html`<small class="shipment-grouped-secondary">${esc(text`İptal edilen ${cancelled.cancelledQuantity} adet`)}</small>`:'';
 return cell('orderIdentity',field('orderNumber','Sipariş numarası',row.orderNumber,{primary:true})
  +field('orderedAt','Sipariş tarihi ve saati',row.orderedAt,{formatted:time})
  +(row.provider?html`<small class="shipment-grouped-secondary">${esc(row.provider)}</small>`:'')
  +html`<small class="shipment-operation-status">${esc(shipmentStatusLabel(row))}</small>`+partial+editActions);
}
function sumItems(items,read){
 let total=0;
 for(const item of items){
  const value=read(item);
  if(!Number.isSafeInteger(value)||!Number.isSafeInteger(total+value))return null;
  total+=value;
 }
 return total;
}
function productCell(items){
 return cell('productCode',shipmentSkuLines(items,{
  codeView:code=>field('productCode','Ürün kodu',code,{primary:true,copyValue:code}),
  extraDetails:group=>{
   const amount=items.length>1?sumItems(group,shipmentTableAmountCents):null;
   return amount===null?'':html`<small>Ürünün fatura tutarı ${esc(money(amount))}</small>`;
  },
 }));
}
function deliveryAddressFields(items,billingAddress){
 const seen=new Set(),fields=[];
 for(const item of items){
  const key=JSON.stringify([item.deliveryAddress,item.province,item.district].map(shipmentAddressKey));
  if(seen.has(key))continue;
  seen.add(key);
  fields.push(field('deliveryAddress','Teslimat adresi',item.deliveryAddress,{
   name:true,formatted:esc(shipmentDeliveryAddressText(item,billingAddress)),
   copyValue:item===items[0]?undefined:item.deliveryAddress,
  })+shipmentDeliveryLocation(item));
 }
 return fields.join('');
}
export function invoiceShipmentCells(row,editActions='',items=[row]){
 const billing=shipmentInvoiceFields(row),amount=sumItems(items,shipmentTableAmountCents);
 const amountLabel=amount===null?'':money(amount);
 const details=amount===null?'':shipmentPaymentDetails(items);
 const email=present(row.recipientEmail)?esc(row.recipientEmail).replace(/([@._-])/g,'$1<wbr>'):undefined;
 return orderCell(row,editActions)
  +cell('billingName',billingNameField('recipientName','Alıcı adı',row.recipientName,{primary:true})
   +billingNameField('invoiceName','Fatura adı',row.invoiceName))
  +cell('billingContact',field('recipientEmail','E-posta',row.recipientEmail,{formatted:email})+field('phone','Telefon',row.phone))
  +productCell(items)
  +cell('billingAddress',field('billingAddress','Fatura adresi',billing.invoiceAddress,{name:true})
   +deliveryAddressFields(items,billing.invoiceAddress))
  +cell('billingTax',field('billingType','Fatura türü',billing.customerType,{primary:true})
   +(billing.customerType==='Kurumsal'?field('billingTaxOffice','Vergi dairesi',billing.taxOffice,{name:true})+field('billingTaxNumber','Vergi numarası',billing.taxNumber,{name:true}):''))
  +cell('billingAmount',details+field('invoiceCents','Fatura tutarı',amountLabel,{name:Boolean(details),primary:true,copyValue:amountLabel}));
}
