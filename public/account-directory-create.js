import {api} from './api.js';
import {html,t} from './i18n.js';
import {toast} from './ui.js';
import {accountDirectoryForm,bindAccountDirectoryScope} from './account-directory-view.js';

/** Keep creation in the settings workspace, with the existing list and filters mounted. */
export function bindInlineAccountCreation(root,{drafts,current,onSaved,fixedAccountType=null}){
 const list=document.createElement('div'),panel=document.createElement('section');
 list.dataset.directoryIndex='';list.append(...root.childNodes);
 panel.dataset.directoryCreatePanel='';panel.hidden=true;panel.setAttribute('aria-label',t('Yeni kullanıcı ekle'));
 root.append(list,panel);let busy=false;
 const active=()=>current()&&panel.isConnected&&!panel.hidden;
 const back=()=>{
  panel.querySelector('form')?.reset();panel.replaceChildren();panel.hidden=true;list.hidden=false;
  list.querySelector('[data-directory-create]')?.focus();
 };
 return (companies,accountType)=>{
  if(!current()||!panel.hidden)return;
  panel.innerHTML=html`<div class="account-directory-heading"><div><h3>Yeni kullanıcı ekle</h3><p class="form-help">Hesap bilgilerini, rolünü ve erişeceği mağazayı belirleyin.</p></div><button class="btn" type="button" data-directory-create-back>Kullanıcı listesine dön</button></div>${accountDirectoryForm(null,companies,{accountType})}`;
  list.hidden=true;panel.hidden=false;
  const form=panel.querySelector('form'),backButton=panel.querySelector('[data-directory-create-back]'),submit=form.querySelector('[type=submit]');
  if(fixedAccountType){
   const select=form.elements.role,option=[...select.options].find(item=>item.value===fixedAccountType);
   select.replaceChildren(option);select.disabled=true;
  }
  bindAccountDirectoryScope(form);
  backButton.onclick=async()=>{if(!busy&&await drafts.canLeave()&&active())back();};
  drafts.track(form,{label:t('Yeni kullanıcı bilgileri'),manageButton:false,save:async values=>{
   if(!active()||busy)return false;
   busy=true;submit.disabled=true;backButton.disabled=true;form.querySelector('[role=alert]').textContent='';
   try{
    const role=fixedAccountType||values.role;
    await api('/admin/users','POST',{...values,role,active:values.active==='true',companyId:role==='client'?Number(values.companyId):null});
    if(!active())return true;
    back();await onSaved(role);toast(t('Yeni kullanıcı oluşturuldu.'));return true;
   }catch(error){if(active())form.querySelector('[role=alert]').textContent=error.message;return false;}
   finally{busy=false;submit.disabled=false;backButton.disabled=false;}
  }});
  form.elements.name.focus();
 };
}
