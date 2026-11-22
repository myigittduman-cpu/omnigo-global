import './static-i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,setCsrf} from './api.js';
import {completeAdminMfa} from './admin-mfa.js';
export function mountAccountSecurity(root=document,{current=()=>true,inline=false,signal}={}){
const form=root.querySelector('#securityForm'),status=root.querySelector('#securityStatus'),message=root.querySelector('#securityMessage'),submit=root.querySelector('#securitySubmit');
if(!form||!status)return;
let enabled=false,busy=false;
async function refresh(){
 const session=await api('/me');if(!root.isConnected||!current())return;setCsrf(session.csrf);
 if(session.user.role!=='admin')throw new Error(i18nT('Bu sayfa yalnızca yönetici hesapları içindir.'));
 const result=await api('/account/security');if(!root.isConnected||!current())return;enabled=result.enabled;
 status.textContent=enabled?i18nT('E-posta ile iki aşamalı doğrulama açık.'):result.available?i18nT('İki aşamalı doğrulama henüz açık değil.'):i18nT('Güvenlik kurulumu hazırlanıyor.');
 root.querySelector('#securityEmail').textContent=i18nText`Kodun gönderileceği adres: ${result.email}`;
 submit.textContent=enabled?i18nT('İki aşamalı doğrulamayı kapat'):i18nT('E-posta ile doğrulamayı aç');
 form.hidden=!result.available||!result.mailReady;
 if(result.available&&!result.mailReady)status.textContent+=i18nT(' E-posta servisi şu anda hazır değil.');
}
form.onsubmit=async event=>{
 event.preventDefault();if(busy||!current())return;busy=true;submit.disabled=true;message.textContent=i18nT('Kod hazırlanıyor…');
 try{
  const pending=await api('/account/security/email/request','POST',{enabled:!enabled,password:form.elements.password.value});form.reset();
  if(!current())return;
  if(inline)form.hidden=true;
  const result=await completeAdminMfa(pending,{settings:true,container:inline?root:null,signal});if(!current())return;
  setCsrf(result.csrf);await refresh();
  message.textContent=result.mfaEnabled?i18nT('İki aşamalı doğrulama etkinleştirildi. Sonraki girişinizde e-posta kodu istenecek.'):i18nT('İki aşamalı doğrulama kapatıldı.');
 }catch(error){if(current()){if(inline)form.hidden=false;message.textContent=error.message;}}finally{form.reset();busy=false;submit.disabled=false;}
};
refresh().catch(error=>{status.textContent=error.status===401?i18nT('Devam etmek için yönetici uygulamasında giriş yapın.'):error.message;form.hidden=true;});
return {busy:()=>busy};
}
if(globalThis.document?.body?.classList.contains('account-page'))mountAccountSecurity();
