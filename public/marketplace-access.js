import {api} from './api.js';
import {esc,icon} from './ui.js';
import {marketplaceAccessDialog,retainAccessCover,finishAccessCover} from './marketplace-access-dialog.js';
export {finishAccessCover} from './marketplace-access-dialog.js';
import {html,t} from './i18n.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {rememberManagementGrant,rememberManagementLease} from './management-access-state.js';
import {protectManagementDialog,releaseManagementAccess} from './management-access-flow.js';

let scope=null,prompt=null,workspace=null;
export function configureManagementAccess(state){workspace=state;}
export async function renewMarketplaceAccess(company,current){
 const state=workspace||scope?.state;if(state?.user?.role!=='admin'||!current())return false;
 const target=Number(company)===0?{id:0,name:'Firma ve kullanıcı yönetimi'}:state.companies.find(item=>Number(item.id)===Number(company));if(!target)return false;
 if(!prompt)prompt=ensureMarketplaceAccess(state,target,{force:true}).finally(()=>prompt=null);
 else if(Number(scope?.company.id)!==Number(company))return false;
 const granted=await prompt;finishAccessCover();if(granted&&current()){const dialog=globalThis.document?.getElementById('dialog');if(dialog?.open)protectManagementDialog(dialog);}
 return granted&&current();
}
/** Password remains only in the live form; no local/session storage or account changes. */
export async function ensureMarketplaceAccess(state,company,{force=false}={}){
 if(state.user?.role!=='admin')return false;
 const token=workspaceToken(state),path='/admin/marketplace-access?company='+Number(company.id);
 scope={state,company,token};
 const status=await api(path);if(!sameWorkspace(state,token))return false;rememberManagementLease(company.id,status);if(status.granted&&!force)return true;
 let busy=false,accepted=false,resolveAccess;
 const outcome=new Promise(resolve=>{resolveAccess=resolve;});
 const secret=(name,label,confirmation=false)=>html`<label><span>${label}</span><span class="marketplace-access-secret"><input type="password" name="${name}" required minlength="${status.configured?1:12}" maxlength="512" autocomplete="${status.configured?'current-password':'new-password'}"><button class="btn" type="button" data-access-eye="${name}" aria-label="${confirmation?'Şifre tekrarını göster':'Şifreyi göster'}" aria-pressed="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span></label>`;
 const dialog=marketplaceAccessDialog(html`<section class="marketplace-access-intro"><span class="customer-action-icon">${icon('connections')}</span><h3>${esc(company.name)}</h3><p>${status.configured?'Müşteri yetkileri, firma ve kullanıcı ayarları ile pazaryeri ayarları ortak Omnigo Admin şifresiyle korunur.':'Üç yönetim alanını korumak için ortak Omnigo Admin şifresini belirleyin. Bu şifre tüm Omnigo yetkilileri için aynı olacaktır.'}</p></section><form class="form-grid marketplace-access-form" autocomplete="off">${secret('password',status.configured?'Omnigo Admin şifresi':'Yeni Omnigo Admin şifresi')}${status.configured?'':secret('confirmation','Şifre tekrar',true)}<p class="form-error" role="alert"></p><button class="btn primary" type="submit">${status.configured?'Doğrula ve aç':'Şifreyi belirle ve aç'}</button><small class="form-help">${status.configured?'Doğrulama bu kullanıcı için üç yönetim alanında 15 dakika geçerlidir. Menüden veya uygulamadan çıkmak süreyi uzatmaz.':'En az 12 karakter kullanın. Şifre, pazaryeri veya kişisel giriş şifrenizden farklı olmalı.'}</small></form>`,()=>busy||accepted);
 const form=dialog.querySelector('form'),current=()=>dialog.open&&dialog.contains(form)&&sameWorkspace(state,token);
 form.addEventListener('click',event=>{const eye=event.target.closest('[data-access-eye]');if(!eye||busy)return;const input=form.elements[eye.dataset.accessEye];input.type=input.type==='password'?'text':'password';eye.setAttribute('aria-pressed',String(input.type==='text'));eye.setAttribute('aria-label',t(input.type==='text'?'Şifreyi gizle':'Şifreyi göster'));});
 form.onsubmit=async event=>{event.preventDefault();if(busy||!current()||!form.reportValidity())return false;busy=true;const button=form.querySelector('[type=submit]'),error=form.querySelector('[role=alert]');button.disabled=true;error.textContent='';
  try{const result=await api(path,'POST',{password:form.elements.password.value,...(status.configured?{}:{setup:true,confirmation:form.elements.confirmation.value})},{confirmed:true});if(!current())return false;rememberManagementGrant(company.id,result);rememberManagementLease(company.id,result);accepted=true;for(const input of form.querySelectorAll('input'))input.value='';button.textContent=t('Bölüm açılıyor…');retainAccessCover(dialog);resolveAccess(sameWorkspace(state,token));return true;}
  catch(e){if(current())error.textContent=e.message;return false;}
  finally{busy=false;if(current())button.disabled=accepted;}
 };
 dialog.addEventListener('close',()=>{for(const input of form.querySelectorAll('input'))input.value='';if(!accepted)void releaseManagementAccess();resolveAccess(accepted&&sameWorkspace(state,token));},{once:true});
 return outcome;
}
