import {labelMap as localizedLabels} from './i18n.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,money,openDialog} from './ui.js';
import {operationProducts} from './operation-notification-products.js';
import {notificationTime} from './notification-time.js';
import {sameWorkspace} from './workspace-context.js';
import {loadNotificationDetail} from './notification-detail.js';
import {notificationFields} from './notification-detail-view.js';
import {inventoryNotificationDetail,inventoryNotificationTitle} from './inventory-notification.js';
import {marketplaceImportTitle,marketplaceImportReportView} from './marketplace-import-report.js';
import {cancellationNotice,customerOrderView} from './customer-order-notifications.js';
import {isUpcomingPayoutNotice,reviewUpcomingPayout,upcomingPayoutNoticeDetail} from './upcoming-payout-review.js';
import {upcomingPaymentDate} from './upcoming-payment-model.js';

const labels=localizedLabels({new_order:'Yeni sipariş geldi',cancelled:'Sipariş iptal edildi',cancellation_requested:'Sipariş için iptal talebi geldi',delivery_added:'Şehir Dışı Teslimatlar listesine eklendi',shipment_added:'Şehir Dışı Sevkiyat tablosuna eklendi',reconciliation_issue:'Sipariş mutabakatında fark var',reconciliation_recovered:'Sipariş mutabakatı düzeldi'});
export function operationNotificationLabel(item){if(isUpcomingPayoutNotice(item)&&item.payoutChannel&&item.payoutDate)return esc(i18nText`${item.payoutChannel} · ${upcomingPaymentDate(item.payoutDate)} tarihli hakediş tutarı değişti`);if(item.message)return esc(item.message);const importTitle=marketplaceImportTitle(item);if(importTitle)return esc(importTitle);const name=String(item.buyerName||'').trim();return (item.events||[]).filter(event=>labels[event]).map(event=>esc(name&&['delivery_added','shipment_added','cancelled'].includes(event)?i18nText`${name} siparişi ${event==='cancelled'?i18nT('iptal edildi'):event==='shipment_added'?i18nT('Şehir Dışı Sevkiyat listesine eklendi'):i18nT('Şehir Dışı Teslimatlar listesine eklendi')}`:labels[event])).join(' · ')||i18nT('Sipariş bilgileri güncellendi');}
export function operationNotificationDetail(event){
 if(event.events?.includes('store_setup_completed'))return i18nHtml`<section class="notification-detail-summary"><p>Kurulumda seçilen canlı veri aktarımları doğrulandı.</p>${notificationTime(event.createdAt)}</section>`;
 if(isUpcomingPayoutNotice(event))return upcomingPayoutNoticeDetail(event);
 if(marketplaceImportTitle(event))return marketplaceImportReportView(event);
 if(inventoryNotificationTitle(event))return inventoryNotificationDetail(event);
 const view=customerOrderView(event);
 if(view)return i18nHtml`<section class="notification-detail-summary ${cancellationNotice(event)?'is-cancelled':''}"><p>${operationNotificationLabel(event)}</p>${notificationTime(event.createdAt)}</section><div class="operation-form-actions"><button type="button" class="btn" data-customer-order-notification-view="${view}">${i18nT(view==='deliveries'?'Şehir Dışı Teslimatlar sayfasını aç':'Şehir Dışı Sevkiyat sayfasını aç')}</button></div>`;
 if(event.reconciliation){
  const result=event.reconciliation,areas={orders:i18nT('Siparişler'),sales:i18nT('Aylık satış özeti'),deliveries:i18nT('Şehir Dışı Teslimatlar'),shipments:i18nT('Şehir Dışı Sevkiyat')},types={missing:i18nT('Eksik kayıt'),duplicate:i18nT('Mükerrer kayıt'),amount:i18nT('Tutar / adet farkı'),unexpected:i18nT('Kaynakla eşleşmeyen kayıt'),period:i18nT('Dönem farkı'),status:i18nT('Durum farkı')};
  const money=value=>new Intl.NumberFormat(currentLocale(),{style:'currency',currency:'TRY'}).format(value/100);
  const amounts=values=>i18nText`Satış ${money(values[0])} · İptal ${money(values[1])} · İade ${money(values[2])} · ${values[3]} sipariş`;
  return i18nHtml`<p>${operationNotificationLabel(event)}</p>${notificationTime(event.createdAt)}<p>${esc(event.provider)} · ${result.orderCount} sipariş kontrol edildi. ${result.total} fark bulundu.</p><p>Kayıtlar otomatik değiştirilmedi. Bu sonuç, bildirimin oluşturulduğu andaki tamamlanmış aktarımı gösterir.</p>${result.items.map(item=>i18nHtml`<article class="health-card"><strong>${esc(areas[item.area]||item.area)} · ${esc(types[item.type]||item.type)}</strong><p>Kaynak referansı: ${esc(item.reference)}${item.recordId?i18nText` · Kayıt #${item.recordId}`:''}${item.count?i18nText` · ${item.count} kayıt`:''}</p>${item.expected?i18nHtml`<p>Beklenen: ${esc(amounts(item.expected))}</p><p>Kayıtlı: ${esc(amounts(item.actual))}</p>`:''}${item.field?i18nHtml`<p>${item.field==='invoiceCents'?i18nT('Fatura tutarı'):i18nT('Adet')} — Beklenen: ${esc(item.field==='invoiceCents'?money(item.expectedValue):item.expectedValue)} · Kayıtlı: ${esc(item.field==='invoiceCents'?money(item.actualValue):item.actualValue)}</p>`:''}</article>`).join('')}${result.truncated?i18nHtml('<p>İlk 50 fark gösteriliyor.</p>'):''}<p>Güncel kontrol: Bağlantı kontrolü → Sipariş mutabakatı.</p>`;
 }
 return i18nHtml`<section class="notification-detail-summary ${(event.events||[]).includes('cancelled')?'is-cancelled':''}"><p>${operationNotificationLabel(event)}</p>${notificationTime(event.createdAt)}</section>${notificationFields([[i18nT('İşlemi yapan'),event.actorName],[i18nT('Sipariş sayısı'),event.orderCount],[i18nT('Parça adedi'),event.pieceCount],[i18nT('İade durumu'),event.statusLabel],[i18nT('Müşteri adı'),event.buyerName],[i18nT('Pazaryeri'),event.provider],[i18nT('Sipariş numarası'),event.orderNumber],[i18nT('Şehir'),event.city],[i18nT('Talep edilen adet'),event.quantity],[i18nT('Talep tutarı'),Number.isSafeInteger(event.claimAmountCents)?money(event.claimAmountCents):null],[i18nT('İptal edilen adet'),event.cancelledQuantity],[i18nT('Eklenen sevkiyat satırı'),event.shipmentCount]])}${operationProducts(event)}`;
}
export async function openOperationNotification(item,state,token){
 const orderEvent=(item.events||[]).some(event=>['cancelled','cancellation_requested','return_updated','delivery_added','shipment_added'].includes(event));
 const endpoint=`/notifications/operation/${item.id}?company=${token.company}`;
 const request=signal=>api(endpoint,'GET',undefined,{signal});
 let detail,body;
 const dialog=await loadNotificationDetail(state,token,{title:operationNotificationTitle(item),className:'event-notification-dialog'+(marketplaceImportTitle(item)?' marketplace-import-dialog':'')+(orderEvent?' order-operation-dialog':''),request,titleFor:operationNotificationTitle,render:event=>{
  detail=event;body=document.querySelector('#dialog .dialog-body');
  return operationNotificationDetail(event);
 },read:()=>api(`/notifications/operation/${item.id}/read?company=${token.company}`,'POST',{})});
 const current=()=>body&&dialog.open&&dialog.querySelector('.dialog-body')===body&&sameWorkspace(state,token);
 const bindTarget=()=>{
  const target=body?.querySelector('[data-customer-order-notification-view]');
  if(target&&current())target.onclick=()=>{
   if(!current())return;
   dialog.close();
   window.dispatchEvent(new CustomEvent('omnigo-order-open',{detail:{type:'operation',companyId:Number(token.company),view:target.dataset.customerOrderNotificationView}}));
  };
 };
 bindTarget();
 const button=body?.querySelector('button[data-upcoming-review]');
 if(button&&state.user?.role==='admin'&&detail?.payoutChange&&Number(state.data?.company?.id)===Number(token.company)&&current()){
  button.onclick=async()=>{
   button.disabled=true;
   try{
    await reviewUpcomingPayout(detail.payoutChange,{company:state.data.company,current,onSave:async()=>{
     const latest=await request();
     if(!current())return;
     body.innerHTML=operationNotificationDetail(latest);
     bindTarget();
     await document.querySelector('[data-reporting="payments"]')?.reportRefresh?.();
    }});
   }finally{button.disabled=false;}
  };
 }
 return dialog;
}
export function operationNotificationTitle(event){
 if(event.events?.includes('store_setup_completed'))return i18nT('Mağaza kurulumu tamamlandı');
 return marketplaceImportTitle(event)||inventoryNotificationTitle(event)||(isUpcomingPayoutNotice(event)?i18nT('Hakediş tutarı değişti'):event.events?.includes('payment_updated')?i18nT('Hakediş bildirimi'):event.reconciliation?i18nT('Sipariş mutabakatı'):i18nT('Sipariş işlemi'));
}
