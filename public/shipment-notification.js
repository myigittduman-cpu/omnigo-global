import {labelMap as localizedLabels} from './i18n.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,money,openDialog} from './ui.js';
import {notificationTime} from './notification-time.js';
import {sameWorkspace} from './workspace-context.js';
import {shipmentStatuses} from './shipment-status.js';
import {notificationFields} from './notification-detail-view.js';
import {loadNotificationDetail} from './notification-detail.js';

const labels=localizedLabels({orderedAt:'Sipariş tarihi ve saati',customerName:'Müşteri adı',recipientName:'Alıcı adı',phone:'Tel no',deliveryAddress:'Teslimat adresi',province:'İl',district:'İlçe',productName:'Ürün adı',invoiceCents:'Fatura tutarı',quantity:'Adet',invoiceName:'Fatura adı',invoiceAddress:'Fatura adresi / V.D.',recipientEmail:'Alıcı mail',customerType:'Müşteri tipi',taxNumber:'Vergi numarası',rowStatus:'Durum',invoiced:'Fatura durumu',invoiceDocument:'Fatura görseli',deliveryDocument:'Teslimat evrağı',recordState:'Satır'});
const actions=localizedLabels({create:'yeni satır ekledi',edit:'satırı düzenledi',status:'durumu değiştirdi',document:'belge yükledi',delete:'satırı sildi',restore:'silinen satırı geri getirdi',undo:'eklemeyi geri aldı'});
export function shipmentNotificationLabel(item){
 if(item.message)return i18nHtml`<strong>${esc(item.message)}</strong><small class="notification-shipment-fields">${esc(item.provider)} · #${Number(item.rowId)}</small>`;
 return i18nHtml`<strong>${esc(item.actorName)}</strong>, #${Number(item.rowId)} numaralı sevkiyatta ${esc(actions[item.operation]||i18nT('değişiklik yaptı'))}.<small class="notification-shipment-fields">${esc(item.provider)} · ${esc((item.fields||[]).map(field=>labels[field]||field).join(', '))}</small>`;
}
function value(field,value){
 if(value===null||value===undefined||value==='')return '—';
 if(field==='rowStatus')return shipmentStatuses.find(status=>status.value===value)?.label||i18nT('İşaretlenmedi');
 if(field==='invoiceCents'&&Number.isSafeInteger(value))return money(value);
 if(field==='invoiced')return value?'Fatura kesildi':'Fatura kesilmedi';
 if(field==='orderedAt')return new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'});
 return String(value);
}
export function shipmentNotificationDetail(event){
 return i18nHtml`<div class="shipment-change-detail"><section class="notification-detail-summary"><p>${event.message?esc(event.message):i18nHtml`<strong>${esc(event.actorName)}</strong> ${esc(actions[event.operation]||i18nT('değişiklik yaptı'))}.`}${notificationTime(event.createdAt)}</p></section>${notificationFields([['Sevkiyat kaydı','#'+Number(event.rowId)],['Pazaryeri',event.provider],['Müşteri',event.row?.customerName],['Şehir',event.row?.province],['Sipariş tarihi',value('orderedAt',event.row?.orderedAt)],['Ürün',event.row?.productName,true]])}<div class="notification-change-table-viewport"><table><thead><tr><th>Alan</th><th>Önceki bilgi</th><th>Yeni bilgi</th></tr></thead><tbody>${event.changes.map(change=>i18nHtml`<tr><th>${esc(labels[change.field]||change.field)}</th><td>${esc(value(change.field,change.before))}</td><td>${esc(value(change.field,change.after))}</td></tr>`).join('')}</tbody></table></div></div>`;
}
export async function openShipmentNotification(item,state,token){
 return loadNotificationDetail(state,token,{title:i18nT('Sevkiyat değişikliği'),className:'shipment-notification-dialog',request:signal=>api(`/notifications/shipment/${item.id}?company=${token.company}`,'GET',undefined,{signal}),render:shipmentNotificationDetail,read:()=>api(`/notifications/shipment/${item.id}/read?company=${token.company}`,'POST',{})});
}
