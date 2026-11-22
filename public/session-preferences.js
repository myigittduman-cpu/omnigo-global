import {api} from './api.js';
import {t,html} from './i18n.js';

export function sessionPreferenceView(){
 return html`<form data-session-preference><h3>Bu cihazdaki oturum</h3><label class="settings-field settings-check"><span><input type="checkbox" name="keepSession" disabled> Oturumumu açık tut</span><small>Çıkış yapana veya bu seçeneği kapatana kadar oturumunuz açık kalır. Yalnızca kişisel cihazınızda kullanın.</small></label><div class="settings-actions"><button class="btn primary" type="submit" disabled>Kaydet</button></div><p role="status" data-session-status>Oturum tercihi yükleniyor…</p></form>`;
}

export async function bindSessionPreference(form,{active,onSignedOut,beforeSignOut,drafts}={}){
 const choice=form.elements.keepSession,status=form.querySelector('[data-session-status]');
 const current=()=>form.isConnected&&active();
 try{
  const result=await api('/account/session');if(!current())return;
  choice.checked=result.keepSession;choice.disabled=false;status.textContent='';
 }catch(error){if(current())status.textContent=error.message;return;}
 drafts.track(form,{label:t('Oturumumu açık tut'),save:async()=>{
  status.textContent='';
  try{
   if(!choice.checked&&drafts.hasOtherChanges(form))throw new Error(t('Oturumu kapatmadan önce diğer kaydedilmemiş ayarları tamamlayın veya vazgeçin.'));
   if(!choice.checked&&await beforeSignOut?.()===false)return false;
   if(!current())return false;
   const result=await api('/account/session','PUT',{keepSession:choice.checked});
   if(!current())return false;
   if(result.signedOut){await onSignedOut?.();return true;}
   status.textContent=t('Oturumunuz siz kapatana kadar açık tutulacak.');
   return true;
  }catch(error){if(current())status.textContent=error.message;return false;}
 }});
}
