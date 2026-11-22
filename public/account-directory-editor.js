import {api} from './api.js';
import {html,t} from './i18n.js';
import {openDialog,toast} from './ui.js';
import {accountDirectoryForm,accountDirectoryPortrait,bindAccountDirectoryScope} from './account-directory-view.js';
import {createSettingsDraftGuard} from './settings-drafts.js';
export function openAccountDirectoryEditor(user,companies,{current,onSaved,onDone,host,drafts=createSettingsDraftGuard()}){
 if(!current())return;
 const content=accountDirectoryForm(user,companies)+(user?accountDirectoryPortrait(user)+html`<div class="account-directory-danger"><button class="btn" type="button" data-directory-sessions>Oturumları kapat</button><button class="btn" type="button" data-directory-delete>Kullanıcıyı sil</button><p class="form-help">Rol, mağaza, giriş bilgisi veya şifre değişirse açık oturumlar kapanır. Hesap silme geçmiş satış, stok ve sevkiyat kayıtlarını silmez.</p><p class="form-error" data-directory-action-error role="alert"></p></div>`:'');
 const panel=host||openDialog(t(user?'Kullanıcıyı düzenle':'Yeni kullanıcı'),content,{className:'account-directory-editor',draftGuard:drafts});
 if(host)host.innerHTML=content;
 const form=panel.querySelector('[data-directory-form]'),valid=()=>form.isConnected&&current()&&(host||panel.open);let saving=false;
 const alert=panel.querySelector('[data-directory-action-error]');
 let reset,mailReady=false;
 if(user?.role==='client'&&user.companyId){
  reset=document.createElement('button');reset.type='button';reset.className='btn';reset.dataset.directoryReset='';
  reset.textContent=t('Şifre yenileme e-postası gönder');reset.disabled=true;alert.before(reset);
  if(Number(user.active)){
   void api('/management/customer?company='+Number(user.companyId)).then(data=>{
    if(!valid())return;
    mailReady=Boolean(data.mailReady);reset.disabled=saving||!mailReady;
    if(!mailReady)reset.title=t('E-posta gönderimi henüz etkin değil.');
   }).catch(error=>{if(valid()){reset.title=error.message;alert.textContent=error.message;}});
  }
  reset.onclick=()=>action(()=>api('/management/users/'+user.id+'/reset?company='+Number(user.companyId),'POST',{}),result=>toast(result.message));
 }
 bindAccountDirectoryScope(form);
 const done=async result=>{if(!valid())return;form.elements.password.value='';panel.querySelectorAll('input[type=file]').forEach(input=>input.value='');await onSaved(result?.user);if(!valid())return;if(onDone)await onDone();else if(!host){panel.dispatchEvent(new Event('omnigo-management-saved'));panel.close();}toast(t('Kullanıcı bilgileri güncellendi.'));};
 drafts.track(form,{label:t('Kullanıcı bilgileri'),manageButton:Boolean(user),save:async values=>{
  if(!valid())return false;
  try{const result=await api('/admin/users'+(user?'/'+user.id:''),user?'PUT':'POST',{...values,active:values.active==='true',companyId:values.role==='client'?Number(values.companyId):null,...(user?{revision:user.revision}:{})});await done(result);return true;}catch(error){if(valid())form.querySelector('[role=alert]').textContent=error.message;return false;}
 }});
 // A new form may already be complete through browser autofill: let the native validation run.
 if(!user)form.onsubmit=async event=>{event.preventDefault();if(saving||!valid()||!form.reportValidity())return;const values=Object.fromEntries(new FormData(form));saving=true;const button=form.querySelector('[type=submit]');button.disabled=true;try{await done(await api('/admin/users','POST',{...values,active:values.active==='true',companyId:values.role==='client'?Number(values.companyId):null}));}catch(error){if(valid())form.querySelector('[role=alert]').textContent=error.message;}finally{saving=false;button.disabled=false;}};
 async function action(work,completed=done){
  if(saving||!valid())return;
  if(drafts.hasOtherChanges(null)){alert.textContent=t('Önce kullanıcı bilgisi değişikliklerini kaydedin.');return;}
  saving=true;alert.textContent='';
  const buttons=[...panel.querySelectorAll('button')].map(button=>[button,button.disabled]);
  buttons.forEach(([button])=>button.disabled=true);
  try{await drafts.runOperation(async()=>{const result=await work();if(valid())await completed(result);});}
  catch(error){if(valid())alert.textContent=error.message;}
  finally{saving=false;buttons.forEach(([button,disabled])=>button.disabled=disabled);if(reset)reset.disabled=!mailReady;}
 }
 panel.querySelector('[data-directory-delete]')?.addEventListener('click',()=>action(()=>api('/admin/users/'+user.id,'DELETE',{revision:user.revision})));
 panel.querySelector('[data-directory-sessions]')?.addEventListener('click',()=>action(()=>api('/admin/users/'+user.id+'/sessions','POST',{revision:user.revision})));
 return panel;
}
