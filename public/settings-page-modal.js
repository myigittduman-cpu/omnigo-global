import {html,t} from './i18n.js';
import {esc} from './ui.js';
import {loadingState,contentError} from './content-state.js';
import {isDialogCancel} from './dialog-cancel.js';
import {confirmAction} from './action-confirmation.js';
const titles={'/account-security.html':'İki aşamalı doğrulama','/notification-settings.html':'Bildirim ayarları','/privacy.html':'Gizlilik','/account-deletion.html':'Hesap silme başvurusu','/support.html':'Destek'};

/** Reuse the account controllers without navigating or weakening the anti-framing policy. */
export function openSettingsPageModal(url,state,{onClose,host=null,panel=null,isCurrent=()=>true}={}){
 const owner=state.user,prior=document.activeElement,dialog=host||document.createElement('dialog'),abort=new AbortController();let cleanup,controller,revision=0,finished=false;
 const surface=panel||dialog;
 const previous=host?{nodes:[...surface.childNodes],className:surface.className,label:surface.getAttribute('aria-label'),labelledBy:surface.getAttribute('aria-labelledby')}:null;
 if(panel){
  panel.innerHTML=html`<div class="settings-detail-heading"><h3 data-settings-page-title tabindex="-1">${esc(t(titles[url.pathname]))}</h3><button type="button" class="btn" data-settings-page-back>Geri</button></div><div class="settings-page-content">${loadingState()}</div>`;
 }else{
  dialog.className='settings-page-dialog';dialog.setAttribute('aria-label',t(titles[url.pathname]));
  dialog.innerHTML=html`<div class="dialog-head"><h2 id="dialogTitle" data-settings-page-title>${esc(t(titles[url.pathname]))}</h2><button type="button" class="close" aria-label="Kapat">×</button></div><div class="dialog-body settings-page-content">${loadingState()}</div>`;
 }
 const body=surface.querySelector('.settings-page-content'),current=()=>!finished&&dialog.open&&body.isConnected&&state.user===owner&&isCurrent();
 const finish=()=>{
  if(finished)return;finished=true;abort.abort();cleanup?.();body.querySelectorAll('form').forEach(form=>form.reset());
  dialog.removeEventListener('omnigo-dialog-dismiss',dismiss);dialog.removeEventListener('close',closed);dialog.removeEventListener('cancel',cancel);
  if(previous){
   if(!dialog.open||state.user!==owner||!isCurrent())return;
   surface.replaceChildren(...previous.nodes);surface.className=previous.className;
   for(const [name,value] of [['aria-label',previous.label],['aria-labelledby',previous.labelledBy]])if(value===null)surface.removeAttribute(name);else surface.setAttribute(name,value);
  }else dialog.remove();
  if(state.user===owner){if(prior?.isConnected)prior.focus({preventScroll:true});onClose?.();}
 };
 let checking=false;
 const canLeave=async()=>{
  if(checking||controller?.busy?.()||body.querySelector('#deletionForm:not([hidden]) button:disabled'))return false;
  if(!body.querySelector('input[type=password]')?.value&&!body.querySelector('form:not([hidden]) input[type=checkbox]:checked'))return true;
  checking=true;
  try{return await confirmAction(t('Bu bölümdeki girişler kaydedilmeden çıkılsın mı?'),{approveLabel:t('Kaydetmeden devam et')})&&current();}
  finally{checking=false;}
 };
 const close=async()=>{if(!await canLeave()||!current())return false;if(previous)finish();else dialog.close();return true;};
 const dismiss=event=>{event.preventDefault();void close();};
 const cancel=event=>{if(!isDialogCancel(event,dialog)||previous)return;event.preventDefault();void close();};
 const closed=()=>finish();
 surface.querySelector(panel?'[data-settings-page-back]':'.close').onclick=close;dialog.addEventListener('cancel',cancel);dialog.addEventListener('close',closed,{once:true});
 if(previous)dialog.addEventListener('omnigo-dialog-dismiss',dismiss);
 else{document.body.append(dialog);dialog.showModal();}
 async function load(path){
  const version=++revision;cleanup?.();cleanup=null;controller=null;
  try{
   const response=await fetch(path,{credentials:'same-origin',cache:'no-store',signal:abort.signal});if(!response.ok)throw new Error(t('İçerik şu anda açılamıyor.'));
   const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),main=parsed.querySelector('main');if(!main)throw new Error(t('İçerik bulunamadı.'));
   main.querySelectorAll('script,style,iframe,object,embed,dialog,link,nav').forEach(node=>node.remove());
   for(const node of main.querySelectorAll('*'))for(const attribute of [...node.attributes])if(attribute.name.startsWith('on')||(['href','src','action'].includes(attribute.name)&&/^\s*javascript:/i.test(attribute.value)))node.removeAttribute(attribute.name);
   if(!current()||version!==revision)return;
   surface.querySelector('[data-settings-page-title]').textContent=t(titles[path]);surface.setAttribute('aria-label',t(titles[path]));body.innerHTML=html(main.innerHTML);body.scrollTop=0;
   body.querySelector('h1')?.remove();body.querySelectorAll('button').forEach(button=>button.classList.add('btn'));
   const active=()=>current()&&version===revision;
   if(path==='/account-security.html'){const {mountAccountSecurity}=await import('./account-security.js');if(active())controller=mountAccountSecurity(body,{current:active,inline:Boolean(panel),signal:abort.signal});}
   if(path==='/account-deletion.html'){const {mountAccountDeletion}=await import('./account-deletion.js');if(active())await mountAccountDeletion(body,{current:active});}
   if(path==='/notification-settings.html'){const {mountNotificationSettings}=await import('./notification-settings.js');if(active())cleanup=mountNotificationSettings(body,{current:active,initialize:false});}
  }catch(error){if(current()&&version===revision&&error.name!=='AbortError')body.innerHTML=contentError(error.message);}
 }
 body.addEventListener('click',async event=>{const link=event.target.closest('a[href]');if(!link)return;const target=new URL(link.href,location.href);if(target.origin===location.origin){event.preventDefault();if(!titles[target.pathname]){void close();return;}if(await canLeave()&&current())void load(target.pathname);}});
 if(panel)surface.querySelector('[data-settings-page-title]').focus({preventScroll:true});
 void load(url.pathname);return panel?{close}:dialog;
}
