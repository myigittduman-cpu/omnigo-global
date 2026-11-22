import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {toast} from './ui.js';
import {orderAlertGroups,orderAlertText,orderNotificationPresentation,desktopNotificationPresentation} from './order-alert-model.js';
import {backgroundActive,bindBackgroundControls} from './background-notifications.js';
import {canInterruptWithNotification,desktopOperationStatus} from './mobile-notification-policy.js';

import {unlockNotificationSound,playNotificationSound,previewNotificationSound} from './notification-sound.js';
const memory=new Map();
const read=(key)=>{try{return localStorage.getItem(key);}catch{return memory.get(key)??null;}};
const write=(key,value)=>{memory.set(key,value);try{localStorage.setItem(key,value);}catch{/* Private mode uses the current session. */}};
const audible=()=>true;
function unlock(){void unlockNotificationSound();}
export function bindOrderSoundPreview(root){
 const button=root.querySelector('[data-preview-order-sound]');if(!button)return;
 button.onclick=async()=>{
  button.disabled=true;button.classList.add('is-playing');
  try{
   if(window.__OMNIGO_PREVIEW_SOUND__)await window.__OMNIGO_PREVIEW_SOUND__();
   else if(window.__OMNIGO_ORDER_NOTIFY__)toast(i18nT('Ses önizlemesi için uygulamayı güncelleyin.'));
   else await previewNotificationSound();
  }catch(error){toast(error.message||i18nT('Ses çalınamadı. Cihazınızın ses ayarlarını kontrol edin.'));}
  finally{setTimeout(()=>{button.disabled=false;button.classList.remove('is-playing');},1500);}
 };
}
export function bindOrderAlertControls(root,state){
 if(state?.user){bindBackgroundControls(root,state.user);return;}
 const enable=root.querySelector('[data-enable-order-alerts]'),sound=root.querySelector('[data-order-sound]'),status=root.querySelector('[data-order-alert-status]');
 const refresh=()=>{sound.hidden=true;sound.textContent=i18nT('Ses açık');sound.setAttribute('aria-pressed',String(audible()));enable.hidden=Boolean(window.__OMNIGO_ORDER_NOTIFY__)||window.Notification?.permission==='granted';};
 refresh();sound.onclick=null;
 enable.onclick=async()=>{
  unlock();if(!window.Notification){status.textContent=i18nT('Masaüstü bildirimleri için yönetici uygulamasını güncelleyin.');return;}
  const permission=await Notification.requestPermission();refresh();
  status.textContent=permission==='granted'?i18nT('Masaüstü bildirimleri açık.'):i18nT('Bildirimleri tarayıcı izinlerinden açabilirsiniz.');
 };
}
async function announce(items){
 const groups=orderAlertGroups(items.filter(item=>canInterruptWithNotification(item)));let sound=audible();
 for(const group of groups){
  const text=orderAlertText(group);
  if(!document.hidden)toast(group.type&&group.type!=='order'?text.title:`${text.title} · ${group.count} adet`);
  if(backgroundActive())continue;
  if(window.__OMNIGO_ORDER_NOTIFY__){
   if(['support','user'].includes(group.type)&&!window.__OMNIGO_USER_NOTIFY__)continue;
   if(['operation','shipment'].includes(group.type)&&!window.__OMNIGO_OPERATION_NOTIFY__)continue;
   if(group.type==='integration'&&(!window.__OMNIGO_INTEGRATION_NOTIFY__||group.status==='recovered'&&!window.__OMNIGO_RECOVERY_NOTIFY__))continue;
   const status=group.type==='operation'?desktopOperationStatus(group,window.__OMNIGO_NOTICE_SCHEMA__??1):['shipment','support','user'].includes(group.type)?group.operation:group.status;
   if(group.type==='operation'&&!status)continue;
   try{await window.__OMNIGO_ORDER_NOTIFY__({provider:group.provider,company:group.company,count:group.count,sound,presentation:desktopNotificationPresentation(group),destination:group.url||null,eventType:group.type||'order',status});sound=false;}catch{/* The permanent bell event is still available. */}
  }else if(window.Notification?.permission==='granted'){
   try{const presentation=orderNotificationPresentation(group);const notice=new Notification(presentation.title,{body:presentation.body,icon:'/assets/omnigo-round-v3.png',tag:`event-${group.type||'order'}-${group.id}`,silent:true});notice.onclick=()=>{window.focus();window.dispatchEvent(new CustomEvent('omnigo-order-open',{detail:group}));notice.close();};setTimeout(()=>notice.close(),7000);}catch{/* Unsupported browsers keep the in-app notification. */}
  }
 }
 if(groups.length&&!window.__OMNIGO_ORDER_NOTIFY__&&!backgroundActive())playNotificationSound();
}
export function startOrderAlerts(state,onArrival){
 let busy=false,failures=0;
 async function tick(){
  if(busy||!['admin','client'].includes(state.user?.role)||!navigator.onLine)return;
  const user=state.user.id,key=`omnigo-order-cursor:${user}`;busy=true;
  const poll=async()=>{
   const stored=read(key),parsed=stored===null?null:Number(stored),cursor=Number.isSafeInteger(parsed)&&parsed>=0?parsed:null;
   const data=await api(`/notifications/orders?events=all&recovery=1&operations=1${cursor===null?'':`&after=${cursor}`}`);
   if(state.user?.id!==user||!['admin','client'].includes(state.user?.role))return;
   if(!Number.isSafeInteger(data.cursor)||data.cursor<0)throw new Error(i18nT('Bildirim konumu okunamadı.'));
   // Persist before displaying: refreshing or opening another tab cannot replay the sound.
   write(key,String(data.cursor));await announce((data.items||[]).filter(item=>item.type!=='resolved'));
   if(data.items?.length)await onArrival();failures=0;
  };
  try{if(navigator.locks)await navigator.locks.request(key,{ifAvailable:true},lock=>lock?poll():undefined);else await poll();}
  catch{failures++;}finally{busy=false;}
 }
 let timer;
 const schedule=()=>{timer=setTimeout(async()=>{await tick();schedule();},Math.min(120000,15000*2**Math.min(failures,3)));};
 schedule();window.addEventListener('online',tick);window.addEventListener('omnigo-session-ready',tick);
 return ()=>{clearTimeout(timer);window.removeEventListener('online',tick);window.removeEventListener('omnigo-session-ready',tick);};
}
