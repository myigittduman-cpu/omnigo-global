import {api} from './api.js';
import {t,html} from './i18n.js';

export function sessionPreferenceView(){
 return html`<form data-session-preference><h3>Bu cihazdaki oturum</h3><label class="settings-field settings-check"><span><input type="checkbox" name="keepSession" disabled> Oturumumu açık tut</span><small>Seçiminiz otomatik kaydedilir. Çıkış yapana veya bu seçeneği kapatana kadar oturumunuz açık kalır. Yalnızca kişisel cihazınızda kullanın.</small></label><p role="status" data-session-status>Oturum tercihi yükleniyor…</p></form>`;
}

export async function bindSessionPreference(form,{active,onSignedOut,beforeSignOut,drafts}={}){
 const choice=form.elements.keepSession,status=form.querySelector('[data-session-status]');
 const current=()=>form.isConnected&&active();
 let persisted;
 try{
  const result=await api('/account/session');if(!current())return;
  persisted=result.keepSession;choice.checked=persisted;choice.disabled=false;
  status.textContent=persisted?t('Oturumunuz siz kapatana kadar açık tutulacak.'):'';
 }catch(error){if(current())status.textContent=error.message;return;}
 const entry=drafts.track(form,{label:t('Oturumumu açık tut'),manageButton:false,save:async()=>{
  const keepSession=choice.checked;
  status.textContent=t('Kaydediliyor…');
  try{
   if(!keepSession&&drafts.hasOtherChanges(form))throw new Error(t('Oturumu kapatmadan önce diğer kaydedilmemiş ayarları tamamlayın veya vazgeçin.'));
   if(!keepSession&&await beforeSignOut?.()===false){status.textContent='';return false;}
   if(!current())return false;
   const result=await api('/account/session','PUT',{keepSession},{confirmed:keepSession});
   persisted=result.keepSession;
   if(!current())return false;
   if(result.signedOut){await onSignedOut?.();return true;}
   status.textContent=t('Oturumunuz siz kapatana kadar açık tutulacak.');
   return true;
  }catch(error){if(current())status.textContent=error.message;return false;}
 }});
 choice.addEventListener('change',async()=>{
  if(await entry.save()===false&&current()){
   choice.checked=persisted;entry.reset();
  }
 });
}
