import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {workspaceIcon} from './workspace-icons.js';
import {showFeedback} from './save-feedback.js';
export const pageRefreshButton=()=>i18nHtml`<div class="page-refresh-group"><button type="button" class="btn page-refresh" data-page-refresh>${workspaceIcon('refresh')} Yenile</button></div>`;
export function markPageUpdated(){
 const label=document.querySelector('[data-page-updated]');if(label)label.textContent=i18nT('Son yenileme: ')+new Date().toLocaleTimeString(currentLocale(),{timeZone:'Europe/Istanbul',hour:'2-digit',minute:'2-digit'});
}
export function bindPageRefresh(app,{refresh,current,notify}){
 const button=app.querySelector('[data-page-refresh]');if(!button)return;
 let pending=false;
 button.onclick=async()=>{
  if(pending)return;pending=true;button.disabled=true;button.classList.add('is-refreshing');button.innerHTML=workspaceIcon('refresh')+i18nT(' Yenileniyor…');
  try{if(await refresh()!==false&&current()){markPageUpdated();showFeedback(i18nT('Liste yenilendi.'),{kind:'success',force:true});}}
  catch(error){if(current())notify(error.message);}
  finally{pending=false;if(button.isConnected){button.disabled=false;button.classList.remove('is-refreshing');button.innerHTML=workspaceIcon('refresh')+i18nT(' Yenile');}}
 };
}
