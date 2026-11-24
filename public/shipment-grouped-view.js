import {t as i18nT,currentLanguage,currentLocale,html as i18nHtml} from './i18n.js';
import {esc,money} from './ui.js';
import {shipmentCopyButton} from './shipment-copy.js';
import {shipmentCustomerType} from './shipment-customer.js';
import {shipmentAmountHidden} from './table-amounts.js';
import {shipmentPaymentDetails} from './shipment-payment-details.js';
import {shipmentDeliveryAddressText,shipmentDeliveryLocation} from './shipment-address-display.js';
export {sameShipmentAddress} from './shipment-address-display.js';

const labels={
 'Müşteri / İletişim':{en:'Customer / Contact',de:'Kunde / Kontakt'},
 'Tutar / Adet':{en:'Amount / Quantity',de:'Betrag / Anzahl'},
};
const label=source=>labels[source]?.[currentLanguage()]??i18nT(source);
export const shipmentGroupedColumns=[
 ['rowStatus','İşlemler'],['orderedAt','Tarih'],['customerContact','Müşteri / İletişim'],
 ['productName','Ürün'],['deliveryInfo','Teslimat'],['invoiceInfo','Fatura'],['totals','Tutar / Adet'],
].map(([key,source])=>Object.defineProperty([key],1,{enumerable:true,get:()=>label(source)}));

const present=value=>value!==null&&value!==undefined&&String(value).trim()!=='';
const display=value=>esc(present(value)?value:'—');

function field(row,key,source,{html,prefix='',primary=false,copy=true}={}){
 const value=html??display(row[key]);
 const button=copy&&present(row[key])?shipmentCopyButton(key,i18nT(source)):'';
 return i18nHtml`<div class="shipment-grouped-field ${primary?'shipment-grouped-primary':''}${button?' table-copy-line':''}"><span class="shipment-grouped-value">${esc(prefix)}${value}</span>${button}</div>`;
}
function cell(key,source,content){return i18nHtml`<td class="shipment-cell-${key}" data-label="${label(source)}"><div class="shipment-grouped-stack">${content}</div></td>`;}
function dateCell(row){
 const date=present(row.orderedAt)?new Date(row.orderedAt):null;
 const html=date&&Number.isFinite(date.getTime())?i18nHtml`<time datetime="${esc(date.toISOString())}"><span>${esc(date.toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric'}))}</span><span class="shipment-grouped-secondary">${esc(date.toLocaleTimeString(currentLocale(),{timeZone:'Europe/Istanbul',hour:'2-digit',minute:'2-digit'}))}</span></time>`:display(row.orderedAt);
 return cell('orderedAt','Tarih',field(row,'orderedAt','Sipariş Tarihi ve Saati',{html}));
}
function customerCell(row){
 const recipientSource=row.customerNameSource==='recipient';
 const name=field(row,'customerName','Müşteri Adı',{primary:true,prefix:i18nT('Müşteri adı')+': ',html:recipientSource?'—':undefined,copy:!recipientSource});
 const notice=recipientSource?i18nHtml`<small class="shipment-grouped-secondary">Sipariş sahibi bildirilmedi</small>`:'';
 const recipient=present(row.recipientName)?field(row,'recipientName','Alıcı Adı',{prefix:i18nT('Alıcı adı')+': '}):i18nHtml('<span class="shipment-data-warning">Alıcı adı eksik</span>');
 const email=display(row.recipientEmail).replace(/([@._-])/g,'$1<wbr>');
 return cell('customerContact','Müşteri / İletişim',notice+name+recipient+field(row,'phone','Tel No')+field(row,'recipientEmail','Alıcı Mail',{html:email}));
}
function deliveryCell(row){
 return cell('deliveryInfo','Teslimat',field(row,'deliveryAddress','Teslimat Adresi',{html:esc(shipmentDeliveryAddressText(row))})+shipmentDeliveryLocation(row));
}
function invoiceCell(row){
 const type=shipmentCustomerType(row).split('\n').map(esc).join('<br>');
 return cell('invoiceInfo','Fatura',field(row,'invoiceName','Fatura Adı',{primary:true})+field(row,'customerType','Müşteri Tipi',{html:type||'—'})+field(row,'invoiceAddress','Fatura Adresi'));
}
function totalsCell(row,invoiceAction){
 const hidden=shipmentAmountHidden(row),amount=hidden?'—':Number.isSafeInteger(row.invoiceCents)?esc(money(row.invoiceCents)):display(row.invoiceCents);
 const quantity=i18nHtml`<span class="shipment-grouped-secondary">${display(row.quantity)} ${i18nT('adet')}</span>`;
 const returned=row.marketplaceReturn?.returnedQuantity>0?i18nHtml`<small class="shipment-return-quantity">İade: ${esc(row.marketplaceReturn.returnedQuantity)}<br>Kalan: ${esc(row.marketplaceReturn.remainingQuantity)}</small>`:'';
 const details=hidden?'':shipmentPaymentDetails([row]);
 const invoice=field(row,'invoiceCents','Fatura Tutarı',{html:amount,primary:true,copy:!hidden});
 return cell('totals','Tutar / Adet',(details?quantity+returned+details+i18nHtml`<small class="shipment-grouped-secondary">Fatura tutarı</small>`+invoice:invoice+quantity+returned)+invoiceAction);
}
// Pure presentation: field keys, copy values, amounts and source records are unchanged.
export function groupedShipmentCells(row,invoiceAction=''){
 return dateCell(row)+customerCell(row)+cell('productName','Ürün',field(row,'productName','Ürün Adı',{primary:true}))+deliveryCell(row)+invoiceCell(row)+totalsCell(row,invoiceAction);
}
