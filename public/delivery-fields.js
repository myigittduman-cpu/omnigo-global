import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';

const text=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'').trim();
export function deliveryOrderDate(value){
 if(!value)return '';
 const raw=text(value),date=new Date(/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(raw)?raw.replace(' ','T')+'+03:00':raw);
 return Number.isNaN(date.getTime())?'':date.toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).replace(',', '');
}
export function deliveryFields(order,sender={}){
 const details=order.details||{};
 return [
  [i18nT('Gönderici Adı'),text(sender.name)],[i18nT('Gönderici Telefon'),text(sender.phone)],
  [i18nT('Alıcı Ad/Soyad'),text(details.recipientName||order.recipientName)],
  [i18nT('Alıcı Adresi'),text(details.deliveryAddress||order.address)],
  [i18nT('Şehir / Semt / PK'), [details.province||order.city,details.neighborhood,details.district||order.district,details.postalCode].map(text).filter(Boolean).join(' / ')],
  ['Ev/Cep Telefonu',text(details.phone||order.phone)],
  [i18nT('Sipariş Tarihi'),deliveryOrderDate(details.orderedAt||order.createdAt)],
  [i18nT('Sipariş Numarası'),text(order.orderNumber)]
 ];
}
export function deliveryProducts(order){
 return (order.products||[]).map(product=>({title:text(product.title),sku:text(product.sku),quantity:product.quantity}));
}
