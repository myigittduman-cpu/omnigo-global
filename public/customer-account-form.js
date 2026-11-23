import {api} from './api.js';
import {html,t} from './i18n.js';
import {esc,openDialog,toast} from './ui.js';
import {addDialogBack} from './dialog-return.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {bindAccountPassword} from './account-credential-input.js';
import {ensureMarketplaceAccess} from './marketplace-access.js';
import {customerAccountRoleField} from './customer-account-roles.js';

export async function createCustomerAccount(state,onSaved,{onBack=null,onDone=null,companyId=state.company,host=null,drafts,current=()=>true}={}){
 if(state.user?.role!=='admin'||!current())return;
 const token=workspaceToken(state);
 const authorized=()=>current()&&state.user?.role==='admin'&&sameWorkspace(state,token);
 const company=state.companies.find(item=>Number(item.id)===Number(companyId));
 if(!company||!await ensureMarketplaceAccess(state,company)||!authorized())return;
 const content=html`<p class="form-help">Kullanıcı yalnız seçtiğiniz mağazaya erişir.</p><form class="form-grid" autocomplete="off"><label>Ad soyad<input name="name" required maxlength="100" autocomplete="off"></label><label>E-posta<input name="email" type="email" required maxlength="200" autocomplete="off"><small>Bilgilendirme ve şifre kurtarma için kullanılır.</small></label><label>Kullanıcı adı<input name="username" required minlength="5" autocomplete="off"><small>Girişte kullanılır. Sonradan yalnız yönetici değiştirebilir.</small></label><label>Bağlı mağaza<select name="companyId" disabled><option value="${Number(company.id)}">${esc(company.name)}</option></select><small>Hesap ve başlangıç yetkileri bu mağazaya bağlanır.</small></label>${customerAccountRoleField()}<label>Kullanıcı şifresi<input name="password" type="password" required autocomplete="new-password"><small>Belirlediğiniz şifre ilk girişten sonra da geçerlidir.</small></label><p class="form-error full-width" role="alert"></p><button class="btn primary" type="submit">Kullanıcıyı kaydet</button></form>`;
 const panel=host||openDialog(t('Yeni kullanıcı ekle'),content,{className:'customer-settings-dialog account-editor-dialog'});
 if(host)host.innerHTML=content;
 else{
  if(onBack)addDialogBack(panel,onBack,'Kullanıcı listesine dön');
 }
 const form=panel.querySelector('form'),storeId=Number(company.id);
 const valid=()=>authorized()&&form.isConnected&&(Boolean(host)||panel.open);
 bindAccountPassword(form.elements.password);
 const suggest=document.createElement('button');
 suggest.type='button';suggest.className='text-btn';suggest.textContent=t('Şifre öner');
 const input=form.elements.password;
 input.closest('label').append(suggest);
 suggest.onclick=async()=>{
  const previous=input.value;
  suggest.disabled=true;
  try{
   const result=await api('/management/initial-password?company='+storeId);
   if(valid()&&input.value===previous){
    input.value=result.password;
    input.dispatchEvent(new Event('input',{bubbles:true}));
   }
  }catch(error){if(valid())form.querySelector('[role=alert]').textContent=error.message;}
  finally{suggest.disabled=false;}
 };
 const save=async values=>{
  if(!valid())return false;
  try{
   await api('/users','POST',{...values,companyId:storeId});
   if(!valid())return true;
   form.reset();form.elements.password.value='';
   if(onDone){await onSaved?.();if(valid())await onDone();}
   else if(!host){
    panel.dispatchEvent(new Event('omnigo-dialog-navigation'));
    await new Promise(resolve=>{panel.addEventListener('close',resolve,{once:true});panel.close();});
    if(authorized())await onSaved?.();
   }
   toast(t('Kullanıcı oluşturuldu.'));return true;
  }catch(error){if(valid())form.querySelector('[role=alert]').textContent=error.message;return false;}
 };
 if(host){drafts.track(form,{label:t('Yeni kullanıcı bilgileri'),save});return;}
 const submit=form.querySelector('[type=submit]');
 form.onsubmit=async event=>{
  event.preventDefault();if(submit.disabled||!valid()||!form.reportValidity())return false;
  submit.disabled=true;
  try{return await save(Object.fromEntries(new FormData(form)));}finally{submit.disabled=false;}
 };
}
