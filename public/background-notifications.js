import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {askMobilePermission,permissionChoice,rememberPermission,showPermissionError} from './mobile-permission.js';
import {api} from './api.js';
import {mobileAvailable,mobileActive,mobileSound,initializeMobile,stopMobile,bindMobileControls} from './mobile-notifications.js';
import {registerWebPush} from './web-push-binding.js';
let owner=null,binding=null,busy=false,permissionController=null,generation=0,lastReconcile=0,reconciling=null,controlsRefresh=null;
const key='omnigo-notification-device';
const persist=value=>{binding=value;try{if(value)localStorage.setItem(key,JSON.stringify(value));else localStorage.removeItem(key);}catch{}controlsRefresh?.();window.dispatchEvent(new Event('omnigo-push-changed'));};
export const backgroundActive=()=>mobileAvailable()?mobileActive():Boolean(binding?.enabled&&binding.userId===owner?.id);
export const backgroundSound=()=>mobileAvailable()?mobileSound():binding?.sound!==false;
const native=()=>window.__OMNIGO_BACKGROUND__;
const workerReady=()=>Promise.race([navigator.serviceWorker.ready,new Promise((_,reject)=>setTimeout(()=>reject(new Error(i18nT('Bildirim hizmeti açılamadı. Sayfayı yenileyin.'))),5000))]);
export async function initializeBackground(user){
 generation++;reconciling=null;lastReconcile=0;
 permissionController?.abort();permissionController=new AbortController();const signal=permissionController.signal;
 owner=user;binding=null;if(mobileAvailable()){void initializeMobile(user);return;}if(!['admin','client'].includes(user?.role))return;
 try{
  if(native()){
   const current=await native()('status');
   if(current.userId===user.id&&current.id){
    const server=await api('/push/devices/'+current.id+'/attach','POST',{});
    if(server.enabled){if(server.sound===false)await api('/push/devices/'+current.id,'POST',{enabled:true,sound:true});await native()('sound',{sound:true});persist({...current,...server,sound:true,userId:user.id,kind:'desktop'});return;}
   }
   const choiceKey={appId:'com.omnigoglobal.'+user.role,installationId:current.deviceId};
   if(current.id&&!current.optedOut)rememberPermission(choiceKey,'allowed');
   let choice=permissionChoice(choiceKey);
   if(!choice){choice=await askMobilePermission(signal);if(signal.aborted||!choice)return;rememberPermission(choiceKey,choice);}
   if(choice!=='allowed'||signal.aborted)return;
   const registration=await api('/push/register','POST',{kind:'desktop',deviceId:current.deviceId,sound:true});
   await native()('configure',{...registration,deviceId:current.deviceId});
   persist({...registration,token:undefined,deviceId:current.deviceId,kind:'desktop'});
  }else{
   if(globalThis.Notification?.permission!=='granted'){
    const choiceKey={appId:'web:'+location.host,installationId:'browser'};
    if(globalThis.Notification?.permission==='denied'){rememberPermission(choiceKey,'declined');return;}
    let choice=permissionChoice(choiceKey);
    if(!choice){choice=await askMobilePermission(signal);if(signal.aborted||!choice)return;rememberPermission(choiceKey,choice);}
    if(choice==='allowed'&&!signal.aborted){try{await enable();}catch(error){showPermissionError(error.message);}}
    return;
   }
   await reconcileWeb();
  }
 }catch{binding=null;}
}
export async function stopBackground(){
 generation++;reconciling=null;lastReconcile=0;
 permissionController?.abort();
 if(mobileAvailable()){await stopMobile();persist(null);owner=null;return;}
 if(native())await native()('disable');
 if(binding?.kind==='web'){
  try{const registration=await workerReady();const subscription=await registration.pushManager.getSubscription();await subscription?.unsubscribe();}catch{}
 }
 persist(null);owner=null;
}
function webSupport(){
 const ios=/iPad|iPhone|iPod/.test(navigator.userAgent)||navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1;
 if(ios&&!matchMedia('(display-mode: standalone)').matches&&!navigator.standalone)return i18nT('Safari’de Paylaş → Ana Ekrana Ekle. Ardından uygulamayı ana ekrandan açın.');
 if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification' in window))return i18nT('Kapalıyken bildirim için güncel Safari, Chrome veya Edge ile ana ekrana eklenen uygulamayı kullanın.');
 return '';
}
async function enable(){
 if(native()){
  const current=await native()('status');
  const result=await api('/push/register','POST',{kind:'desktop',deviceId:current.deviceId,sound:true});
  await native()('configure',{...result,deviceId:current.deviceId});persist({...result,token:undefined,kind:'desktop'});return;
 }
 const unsupported=webSupport();if(unsupported)throw new Error(unsupported);
 await reconcileWeb(true);
}
function reconcileWeb(explicit=false){
 if(reconciling)return reconciling;
 if(!owner||native()||mobileAvailable()||webSupport())return Promise.resolve();
 const ticket=generation,userId=owner.id,signal=permissionController?.signal;
 const current=()=>generation===ticket&&owner?.id===userId;
 lastReconcile=Date.now();
 const operation=registerWebPush({request:api,workerReady,current,signal,explicit}).then(result=>{
  if(!current())return;
  if(result)persist({...result,userId});
  else{persist(null);if(explicit)throw new Error(i18nT('Bildirim izni kapalı. Cihaz ayarlarından bu uygulamanın bildirimlerine izin verin.'));}
 }).finally(()=>{if(reconciling===operation)reconciling=null;});
 reconciling=operation;return operation;
}
const resumeWeb=()=>{if(owner&&!native()&&!mobileAvailable()&&globalThis.Notification?.permission==='granted'&&Date.now()-lastReconcile>=60000)void reconcileWeb().catch(()=>{});};
window.addEventListener('online',resumeWeb);
window.addEventListener('focus',resumeWeb);
document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')resumeWeb();});
export function bindBackgroundControls(root,user){
 if(mobileAvailable()){bindMobileControls(root,user);return;}
 owner=user;const enableButton=root.querySelector('[data-enable-order-alerts]'),sound=root.querySelector('[data-order-sound]'),test=root.querySelector('[data-test-push]'),status=root.querySelector('[data-order-alert-status]');
 if(!enableButton)return;
 const refresh=()=>{
  if(!root.isConnected)return;
  const active=backgroundActive();enableButton.hidden=active;enableButton.textContent=active?i18nT('Bildirimleri kapat'):i18nT('Bildirimleri aç');
  sound.hidden=true;sound.textContent=backgroundSound()?i18nT('Ses açık'):i18nT('Ses kapalı');sound.setAttribute('aria-pressed',String(backgroundSound()));sound.disabled=busy;test.hidden=!active||binding?.kind!=='web';
  status.textContent=active?i18nT('Uygulama açıkken ve kapalıyken bildirimler açık.'):native()?i18nT('Bildirimler kapalı.'):webSupport()||i18nT('Bu cihaz için bildirimleri etkinleştirin.');
 };
 controlsRefresh=refresh;
 refresh();
 const run=async action=>{if(busy)return;busy=true;enableButton.disabled=true;try{const message=await action();refresh();if(message)status.textContent=message;}catch(error){status.textContent=error.message||i18nT('Bildirim ayarı kaydedilemedi.');}finally{busy=false;enableButton.disabled=false;sound.disabled=false;}};
 enableButton.onclick=()=>run(async()=>{
  if(!backgroundActive())await enable();
 });
 sound.onclick=null;
 test.onclick=()=>run(async()=>{await api('/push/devices/'+binding.id+'/test','POST',{});return i18nT('Deneme sıraya alındı. Bir dakika içinde bildirim gelecek.');});
}

window.addEventListener('omnigo-mobile-ready',async()=>{if(owner){await initializeMobile(owner);window.dispatchEvent(new Event('omnigo-push-changed'));}});

