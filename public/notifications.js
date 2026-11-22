import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {openOperationNotification} from './operation-notification.js';
import {openOrderNotification} from './order-detail.js';
import {loadNotificationDetail} from './notification-detail.js';
import {notificationFields} from './notification-detail-view.js';
import {customerCan} from './customer-access.js';
import {openShipmentNotification} from './shipment-notification.js';
import {api} from './api.js';
import {toast,openDialog,esc} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {notificationList,notificationControls,notificationCount} from './notification-view.js';
import {bindOrderAlertControls,bindOrderSoundPreview} from './order-alerts.js';
import {openTicket} from './support.js';
import {selectedNotificationCategory} from './notification-category.js';
import {acknowledgeCategory} from './notification-visit.js';
import {openUserNotification} from './user-notification.js';
import {openSupportResolution} from './support-resolution-notification.js';
import {canInterruptWithNotification} from './mobile-notification-policy.js';

const styles=document.createElement('link');styles.rel='stylesheet';styles.href='/notifications.css';document.head.append(styles);
let binding;
const bellScope=(state,company)=>state.user?.role==='admin'?'scope=all':'company='+encodeURIComponent(company);
export async function loadNotifications(state,{more=false,force=false}={}){
 if(!['admin','client'].includes(state.user?.role)||!state.company||state.notificationBusy&&!force)return;
 const token=workspaceToken(state),revision=state.notificationRequest=(state.notificationRequest||0)+1;
 const page=0,filter='all';
 const category=selectedNotificationCategory(state);
 const current=()=>sameWorkspace(state,token)&&state.notificationRequest===revision&&selectedNotificationCategory(state)===category;
 try{
  const data=await api(`/notifications?${bellScope(state,token.company)}&page=${page}&filter=${filter}&category=${category}`);
  if(!current())return;
  const previous=state.notificationData?.through?.resolved;
  if(state.user.role==='client'&&canInterruptWithNotification('resolved')&&Number.isSafeInteger(previous)&&data.through?.resolved>previous)toast(i18nT('Destek talebiniz çözüldü.'));
  data.items=(data.items||[]).slice(0,10);data.hasMore=false;
  state.notificationData=data;state.notificationPage=page;state.notificationError='';
 }catch(error){if(!current())return;state.notificationError=error.message;if(error.status===401)throw error;}
}
export function updateNotificationList(root,state){
 const bell=root.querySelector('.notification-bell'),count=notificationCount(state),badge=bell?.querySelector('.notification-count');
 if(badge){badge.textContent=count||'';badge.hidden=!count;bell.setAttribute('aria-label',i18nText`Bildirimler${count?i18nText`, ${count} okunmamış`:''}`);}
 const active=document.activeElement,key=['notification','notificationFilter','notificationCategory','notificationAction'].find(key=>active?.dataset?.[key]),value=key?active.dataset[key]:null;
 const controls=root.querySelector('.notification-controls');if(controls){const html=notificationControls(state);if(controls.innerHTML!==html)controls.innerHTML=html;}
 const body=root.querySelector('.notification-body');if(!body)return;
 const markup=notificationList(state.notificationData,state.notificationError,state.notificationFilter,selectedNotificationCategory(state),state.user?.role==='admin');
 if(body.innerHTML!==markup){const scroll=body.scrollTop;body.innerHTML=markup;body.scrollTop=scroll;}
 if(key){const target=Array.from(root.querySelectorAll('.notification-panel button')).find(button=>button.dataset[key]===value&&!button.disabled)||controls?.querySelector('[aria-pressed="true"]');target?.focus({preventScroll:true});}
}
export function bindNotifications(root,state,render){
 bindOrderSoundPreview(root);
 binding?.abort();binding=new AbortController();
 const center=root.querySelector('.notification-center');if(!center)return;
 bindOrderAlertControls(center,state);
 const bell=center.querySelector('.notification-bell'),panel=center.querySelector('.notification-panel'),options={signal:binding.signal};
 const close=(focus=false)=>{panel.hidden=true;bell.setAttribute('aria-expanded','false');if(focus)bell.focus();};
 bell.addEventListener('click',async()=>{
  if(!panel.hidden){close();return;}
  panel.hidden=false;bell.setAttribute('aria-expanded','true');panel.querySelector('.notification-body').scrollTop=0;
  if(state.notificationBusy)return;
  try{const token=workspaceToken(state),category=selectedNotificationCategory(state);await acknowledgeCategory({
   state,category,current:()=>sameWorkspace(state,token)&&center.isConnected&&selectedNotificationCategory(state)===category,visible:()=>!panel.hidden,
   load:()=>loadNotifications(state,{force:true}),
   send:(category,through)=>api(`/notifications/read-all?${bellScope(state,token.company)}&category=${category}`,'POST',{through},{confirmed:true}),
   update:()=>{if(center.isConnected)updateNotificationList(root,state);}
  });}catch(error){toast(error.message);}
 },options);
 document.addEventListener('pointerdown',event=>{if(!center.contains(event.target))close();},options);
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!panel.hidden){event.preventDefault();close(true);}},options);
 document.addEventListener('focusin',event=>{if(!center.contains(event.target))close();},options);
 center.addEventListener('keydown',event=>{if(event.target===bell&&event.key==='ArrowDown'){event.preventDefault();if(panel.hidden)bell.click();panel.querySelector('button')?.focus();}},options);
 center.addEventListener('click',async event=>{
  const button=event.target.closest('[data-notification],[data-notification-more],[data-notification-retry],[data-notification-filter],[data-notification-category],[data-notification-action]');if(!button||state.notificationBusy)return;
  const token=workspaceToken(state);button.disabled=true;
  try{
   if(button.dataset.notificationFilter||button.dataset.notificationCategory){
    if(button.dataset.notificationFilter)state.notificationFilter=button.dataset.notificationFilter;
    if(button.dataset.notificationCategory){state.notificationCategory=button.dataset.notificationCategory;state.notificationFilter='all';}
    state.notificationPage=0;state.notificationData=state.notificationData?{...state.notificationData,items:[],counts:null,through:null,undoId:null,hasMore:false}:null;state.notificationConfirmDelete=false;state.notificationError='';state.notificationActionError='';
    if(button.dataset.notificationCategory){
     const category=selectedNotificationCategory(state);
     await acknowledgeCategory({state,category,current:()=>sameWorkspace(state,token)&&selectedNotificationCategory(state)===category,
      load:()=>loadNotifications(state,{force:true}),send:(category,through)=>api(`/notifications/read-all?${bellScope(state,token.company)}&category=${category}`,'POST',{through},{confirmed:true}),
      update:()=>{if(center.isConnected)updateNotificationList(root,state);}});
    }else await loadNotifications(state);
    if(sameWorkspace(state,token)&&center.isConnected)updateNotificationList(root,state);return;
   }
   if(button.dataset.notificationAction){
    const action=button.dataset.notificationAction;
    if(action==='delete'||action==='cancel-delete'){state.notificationConfirmDelete=action==='delete';state.notificationActionError='';updateNotificationList(root,state);return;}
    const through=state.notificationData?.through,undoId=state.notificationData?.undoId;
    if(action!=='undo'&&!through)return;
    state.notificationBusy=true;state.notificationRequest++;state.notificationActionError='';updateNotificationList(root,state);
    try{
     const endpoint=action==='confirm-delete'?'dismiss-all':action==='undo'?'undo':'read-all';
     await api(`/notifications/${endpoint}?${bellScope(state,token.company)}&category=${selectedNotificationCategory(state)}`,'POST',action==='undo'?{undoId}:{through});
     if(!sameWorkspace(state,token))return;
     state.notificationConfirmDelete=false;await loadNotifications(state,{force:true});
    }catch(error){if(sameWorkspace(state,token))state.notificationActionError=error.message;}
    finally{if(sameWorkspace(state,token)){state.notificationBusy=false;if(center.isConnected)updateNotificationList(root,state);}}
    return;
   }
   if(button.dataset.notification){
    const item=state.notificationData?.items.find(item=>`${item.type}:${item.id}`===button.dataset.notification);if(!item)return;
    close(true);
    if(state.user.role==='admin'&&item.companyId&&Number(item.companyId)!==Number(state.company)){
     window.dispatchEvent(new CustomEvent('omnigo-order-open',{detail:{...item,noticeId:item.type==='support'?item.targetId:item.id}}));return;
    }
    if(item.type==='integration')await loadNotificationDetail(state,token,{title:i18nT('Bağlantı bilgisi'),className:'event-notification-dialog',request:signal=>api(`/notifications/integration/${item.id}?company=${token.company}`,'GET',undefined,{signal}),titleFor:detail=>`${detail.service} · ${detail.status==='recovered'?i18nT('Bağlantı düzeldi'):i18nT('Bağlantı uyarısı')}`,render:detail=>i18nHtml`<section class="notification-detail-summary"><p>${esc(detail.message)}</p></section>${notificationFields([['Pazaryeri',detail.service],['Tarih',new Date(detail.createdAt).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'})]])}<p>Bağlantı kontrolü bölümünden güncel durumu inceleyebilirsiniz.</p>`,read:()=>api(`/notifications/integration/${item.id}/read?company=${token.company}`,'POST',{})});
    if(item.type==='operation')await openOperationNotification(item,state,token);
    if(item.type==='shipment')await openShipmentNotification(item,state,token);
    if(item.type==='support')await openTicket(item.targetId,state,render);
    if(item.type==='user')await openUserNotification(item,state,token,render);
    if(item.type==='resolved')await openSupportResolution(item,state,token,render);
    if(item.type==='order'){
     if(!customerCan(state,'orders')){toast(i18nT('Bu bölümü görüntüleme yetkiniz yok.'));return;}
     await openOrderNotification(state,item.id);
    }
    if(sameWorkspace(state,token)){await loadNotifications(state);if(center.isConnected)updateNotificationList(root,state);}
   }else{await loadNotifications(state,{more:button.hasAttribute('data-notification-more')});if(sameWorkspace(state,token)&&center.isConnected)updateNotificationList(root,state);}
  }catch(error){toast(error.message);}finally{button.disabled=false;}
 },options);
}
