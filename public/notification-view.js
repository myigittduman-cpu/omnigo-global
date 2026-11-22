import {t as i18nT,text as i18nText,html as i18nHtml,currentLanguage} from './i18n.js';
import {operationNotificationLabel} from './operation-notification.js';
import {shipmentNotificationLabel} from './shipment-notification.js';
import {notificationTime} from './notification-time.js';
import {esc,icon,money} from './ui.js';
import {marketplaceLogo} from './marketplace-branding.js';
import {notificationCategoryLabel,availableNotificationCategories,selectedNotificationCategory,categoryForNotification} from './notification-category.js';
import {cancellationNotice} from './customer-order-notifications.js';

const providers={koctas:['Koçtaş','koctas.png',"Koçtaş’tan"],hepsiburada:['Hepsiburada','hepsiburada.png',"Hepsiburada’dan"],n11:['n11','n11.jpg',"n11’den"],pazarama:['Pazarama','pazarama.webp',"Pazarama’dan"],idefix:['idefix','idefix.png',"idefix’ten"],pttavm:['PTT AVM','pttavm-official-09118.svg',"PTT AVM’den"],trendyol:['Trendyol','trendyol-provided-09144.webp',"Trendyol’dan"]};
export function notificationList(data,error='',filter='all',category='all',showCompany=false){
 const items=(data?.items||[]).filter(item=>['support','order','shipment','operation','user','resolved'].includes(item.type)&&(category==='all'||categoryForNotification(item)===category)).slice(0,10);
 const rows=items.map(item=>{
  const provider=providers[item.provider],support=['support','user','resolved'].includes(item.type);
  const omnigo=['operation','resolved'].includes(item.type);
  const symbol=omnigo?'<img class="notification-omnigo-logo" src="/assets/omnigo-notification-logo-v3.png" alt="Omnigo Global">':item.type==='shipment'?icon('shipments'):support?icon('support'):provider?marketplaceLogo(provider[0]):icon('inbox');
  const label=item.type==='resolved'?i18nHtml`<strong>${esc(item.message||i18nT('Omnigo, destek talebinizi güncelledi.'))}</strong> Talep #${Number(item.targetId)}`:item.type==='user'?i18nHtml`<strong>${esc(item.actorName||item.company)}</strong> ${esc(({created:i18nT('destek talebi oluşturdu.'),reply:i18nT('destek mesajı gönderdi.'),closed:i18nT('destek talebini kapattı.'),reopened:i18nT('destek talebini yeniden açtı.')})[item.operation]||i18nT('destek talebini güncelledi.'))}`:item.type==='integration'?i18nHtml`<strong>${esc(provider?.[0]||i18nT('Pazaryeri'))}</strong> ${item.status==='recovered'?i18nT('bağlantısı düzeldi.'):i18nT('bağlantı / aktarım sorunu.')}`:item.type==='operation'?operationNotificationLabel(item):item.type==='shipment'?shipmentNotificationLabel(item):support?i18nHtml`<strong>${esc(item.actorName||item.company)}</strong> destek talebi oluşturdu.`:i18nHtml`<strong>${esc(currentLanguage()==='tr'?(provider?.[2]||'Pazaryerinden'):(provider?.[0]||i18nT('Pazaryeri')))}</strong> ${item.type==='order'?i18nT('yeni sipariş geldi.'):i18nT('müşterinizin sorusu var.')}`;
  const company=showCompany&&item.company?i18nHtml`<strong class="notification-company">${esc(item.company)}</strong>`:'';
  const details=showCompany&&item.type==='order'?[item.buyerName,Number.isSafeInteger(item.amountCents)?money(item.amountCents):''].filter(Boolean).join(' · '):'';
  return i18nHtml`<li><button type="button" class="notification-item ${cancellationNotice(item)?'notification-cancelled':''} ${item.read?'is-read':'is-unread'}" data-notification="${esc(item.type)}:${Number(item.id)}"><span class="notification-symbol ${omnigo?'omnigo-symbol':support||item.type==='shipment'?'support-symbol':''}">${symbol}</span><span>${company}${label}${details?i18nHtml`<small class="notification-shipment-fields">${esc(details)}</small>`:''}${!showCompany&&['support','user','shipment'].includes(item.type)?i18nHtml`<small class="notification-shipment-fields">${esc(item.company)}</small>`:''}${notificationTime(item.createdAt)}</span><span class="notification-arrow">${icon('arrow')}</span></button></li>`;
 }).join('');
 const emptyMessages=category==='resolved'
  ?{all:'Henüz çözülen destek talebi bildirimi yok.',unread:'Okunmamış destek bildirimi yok.',read:'Okunan destek bildirimi yok.'}
  :{all:category==='customer-orders'?'Henüz sipariş bildirimi yok.':'Henüz bildirim yok.',unread:'Okunmamış bildirim yok.',read:'Okunan bildirim yok.'};
 const empty=i18nT(emptyMessages[filter]||emptyMessages.all);
 return `${error?i18nHtml('<p class="notification-state" role="alert">Bildirimler yüklenemedi. <button type="button" data-notification-retry>Tekrar dene</button></p>'):''}${rows?i18nHtml`<ul class="notification-list">${rows}</ul>`:!error?i18nHtml`<p class="notification-state">${data?empty:i18nT('Bildirimler yükleniyor…')}</p>`:''}`;
}
export function notificationControls(state){
 const counts=state.notificationData?.counts,busy=state.notificationBusy?'disabled':'';
 const category=selectedNotificationCategory(state);
 const categories=availableNotificationCategories(state).map(id=>{const count=state.notificationData?.categoryCounts?.[id]?.unread||0;return i18nHtml`<button type="button" data-notification-category="${id}" aria-pressed="${id===category}" ${busy}><span>${notificationCategoryLabel(state,id)}</span>${count?i18nHtml`<strong aria-label="${count} okunmamış">${count}</strong>`:''}</button>`;}).join('');
 const confirm=state.notificationConfirmDelete?i18nHtml`<div class="notification-confirm"><p>${i18nT('Bu bölümdeki bildirimler listeden kaldırılacak. Kaynak kayıtlar korunur.')}</p><div><button type="button" data-notification-action="confirm-delete" ${busy}>Bildirimleri sil</button><button type="button" data-notification-action="cancel-delete" ${busy}>Vazgeç</button></div></div>`:'';
 return i18nHtml`<div class="notification-categories" role="group" aria-label="Bildirim kategorisi">${categories}</div><div class="notification-actions"><button type="button" data-notification-action="delete" ${busy||!counts?.all||state.notificationError?'disabled':''}>Bu bölümü temizle</button></div>${confirm}${state.notificationActionError?i18nHtml('<p class="notification-state" role="alert">İşlem tamamlanamadı. Tekrar deneyin.</p>'):''}`;
}
export function notificationBell(state){
 if(!['admin','client'].includes(state.user?.role))return '';
 const count=notificationCount(state);
 return i18nHtml`<div class="notification-center"><button type="button" class="notification-bell" aria-label="Bildirimler${count?i18nText`, ${count} okunmamış`:''}" title="Bildirimler" aria-expanded="false" aria-controls="notificationPanel">${icon('notifications')}<span class="notification-count" ${count?'':'hidden'} aria-hidden="true">${count||''}</span></button><section class="notification-panel" id="notificationPanel" aria-label="Bildirimler" hidden><div class="notification-controls">${notificationControls(state)}</div><div class="notification-body">${notificationList(state.notificationData,state.notificationError,state.notificationFilter,selectedNotificationCategory(state),state.user?.role==='admin')}</div><div class="order-alert-controls"><button type="button" data-enable-order-alerts>Masaüstü uyarılarını aç</button><button type="button" data-order-sound aria-pressed="true">Ses açık</button><button type="button" data-test-push hidden>Deneme bildirimi gönder</button><span data-order-alert-status role="status"></span></div></section></div>`;
}
export function notificationCount(state){const count=state.notificationData?.totalUnread??state.notificationData?.counts?.unread;return Number.isSafeInteger(count)&&count>0?count:0;}

