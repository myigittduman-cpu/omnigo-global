import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {askMobilePermission,permissionChoice,rememberPermission,showPermissionError} from './mobile-permission.js';
let owner=null,binding=null,busy=false,generation=0,initializing=null,promptController=null,device=null;
const changed=()=>window.dispatchEvent?.(new Event('omnigo-push-changed'));
export const mobileAvailable=()=>typeof window.__OMNIGO_MOBILE_PUSH__==='function';
const call=(command,data={})=>window.__OMNIGO_MOBILE_PUSH__(command,data);
export const mobileActive=()=>Boolean(binding?.enabled&&binding.userId===owner?.id);
export const mobileSound=()=>binding?.sound!==false;
export function mobileSetupError(config,current){
 if(current.configured===false)return i18nT('Bu uygulama sürümünün bildirim bağlantısı eksik. Bildirim destekli güncel sürümü yükleyin.');
 if(!config[current.kind])return i18nT(config.status?.[current.kind]?.message)||i18nT('Mobil bildirim hizmetinin kurulumu henüz tamamlanmadı.');
 return null;
}
async function register(current,expected=generation){
 const user=owner;
 if(!user||expected!==generation)return;
 const result=await api('/mobile-push/register','POST',{kind:current.kind,appId:current.appId,installationId:current.installationId,token:current.token,environment:current.environment,sound:true,displayVersion:current.displayVersion===2?2:1});
 if(owner?.id!==user.id||expected!==generation)return;
 await call('push_binding',{...result,userId:user.id});
 if(owner?.id!==user.id||expected!==generation)return;
 // Credentials stay in native protected storage, never localStorage or rendered HTML.
 binding={id:result.id,userId:user.id,enabled:true,sound:result.sound};
}
async function refreshMobile(expected,signal){
 let requested=false;
 try{
  let current=await call('push_status');
  if(expected!==generation)return;
  device=current;
  // Existing native bindings are already consented; never prompt them again.
  if((current.enabled||current.id>0)&&!permissionChoice(current))rememberPermission(current,'allowed');
  if(current.userId&&current.userId!==owner.id){
   await call('push_disable');if(expected!==generation)return;
   current=await call('push_status');if(expected!==generation)return;device=current;
  }
  const config=await api('/mobile-push/config');
  if(expected!==generation||mobileSetupError(config,current))return;
  let choice=permissionChoice(current);
  if(!current.enabled&&!choice){
   choice=await askMobilePermission(signal);
   if(expected!==generation||!choice)return;
   rememberPermission(current,choice);
   if(choice==='allowed'){
    requested=true;
    // The permission call follows the user's explicit choice. Denial never blocks login.
    try{current=await call('push_enable');}
    catch(error){
     const status=await call('push_status');
     if(expected===generation&&!status.permission)rememberPermission(current,'declined');
     throw error;
    }
   }
  }else if(!current.enabled&&choice==='allowed'&&current.permission){
   current=await call('push_enable');
  }
  if(expected!==generation||choice==='declined'||!current.permission||!current.token)return;
  await register(current,expected);
 }catch(error){if(requested&&expected===generation)showPermissionError(i18nT(error.message)||i18nT('İnternet bağlantınızı kontrol edip Bildirim ayarlarından yeniden deneyin.'));}
}
export function initializeMobile(user){
 if(initializing&&initializing.userId===user?.id)return initializing.promise;
 const expected=++generation;promptController?.abort();promptController=new AbortController();
 owner=user;binding=null;device=null;
 if(!mobileAvailable()||!user)return Promise.resolve();
 const task={userId:user.id,promise:null};initializing=task;
 task.promise=refreshMobile(expected,promptController.signal).finally(()=>{if(initializing===task)initializing=null;if(expected===generation)changed();});
 return task.promise;
}
export async function stopMobile(){++generation;promptController?.abort();initializing=null;binding=null;owner=null;device=null;if(mobileAvailable())await call('push_disable');}
export function bindMobileControls(root,user){
 owner=user;
 const button=root.querySelector('[data-enable-order-alerts]'),sound=root.querySelector('[data-order-sound]'),test=root.querySelector('[data-test-push]'),status=root.querySelector('[data-order-alert-status]');
 if(!button)return;
 const refresh=()=>{sound.hidden=true;button.hidden=mobileActive();button.disabled=sound.disabled=test.disabled=busy||Boolean(initializing);button.textContent=mobileActive()?i18nT('Bildirimleri kapat'):i18nT('Bildirimleri aç');sound.textContent=mobileSound()?i18nT('Ses açık'):i18nT('Ses kapalı');sound.setAttribute('aria-pressed',String(mobileSound()));test.hidden=!mobileActive();status.textContent=mobileActive()?i18nT('Uygulama açıkken ve kapalıyken bildirimler açık.'):initializing?i18nT('Bildirim izni kontrol ediliyor…'):i18nT('Bildirimleri açmak için cihaz izni gerekir.');};
 const run=async action=>{if(busy||initializing)return;busy=true;button.disabled=sound.disabled=test.disabled=true;try{const message=await action();refresh();if(message)status.textContent=message;}catch(error){status.textContent=i18nT(error.message)||i18nT('Bildirim ayarı kaydedilemedi.');}finally{busy=false;button.disabled=sound.disabled=test.disabled=false;}};
 button.onclick=()=>run(async()=>{
  if(mobileActive())return;
  const config=await api('/mobile-push/config'),current=await call('push_status');
  const setupError=mobileSetupError(config,current);if(setupError)throw new Error(setupError);
  const expected=generation;const enabled=await call('push_enable');if(expected!==generation)return;device=enabled;rememberPermission(enabled,'allowed');await register(enabled,expected);
 });
 sound.onclick=null;
 test.onclick=()=>run(async()=>{await api('/mobile-push/devices/'+binding.id+'/test','POST',{});return i18nT('Deneme bildirimi sıraya alındı.');});
 refresh();
 const onChange=()=>{if(!root.isConnected){window.removeEventListener('omnigo-push-changed',onChange);return;}if(owner?.id===user.id)refresh();};
 window.addEventListener('omnigo-push-changed',onChange);
}
