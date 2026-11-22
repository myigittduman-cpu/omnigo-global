import './static-i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,setCsrf} from './api.js';
import {initializeBackground,bindBackgroundControls} from './background-notifications.js';
export function mountNotificationSettings(root=document,{current=()=>true,initialize=true}={}){
let user=null,disposed=false;
async function setup(){
 try{
  if(!user){const result=await api('/me');user=result.user;setCsrf(result.csrf);}
  if(disposed||!current()||!root.isConnected)return;
  if(initialize)await initializeBackground(user);
  if(disposed||!current()||!root.isConnected)return;
  root.querySelector('#notificationControls').hidden=false;bindBackgroundControls(root,user);
 }catch(error){const status=root.querySelector('[data-order-alert-status]');if(status&&current())status.textContent=error.status===401?i18nT('Önce hesabınıza giriş yapın.'):error.message;}
}
window.addEventListener('omnigo-mobile-ready',setup);void setup();
return ()=>{disposed=true;window.removeEventListener('omnigo-mobile-ready',setup);};
}
if(globalThis.document?.body?.classList.contains('account-page'))mountNotificationSettings(document.body);
