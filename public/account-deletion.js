import './static-i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,setCsrf} from './api.js';
export async function mountAccountDeletion(root=document,{current=()=>true}={}){
const status=root.querySelector('#requestStatus'),form=root.querySelector('#deletionForm');if(!status||!form)return;
try {
 const session=await api('/me');if(!root.isConnected||!current())return;setCsrf(session.csrf);
 status.textContent=i18nText`Talep oluşturulacak hesap: ${session.user.email}`;form.hidden=false;
 form.onsubmit=async event=>{
  event.preventDefault();if(!current()||!form.elements.confirmed.checked)return;
  const button=form.querySelector('button');button.disabled=true;
  try{
   const result=await api('/account/deletion-request','POST',{confirmed:true});
   status.textContent=i18nT(result.message)+i18nText` Talep numarası: ${result.requestId}`;form.hidden=true;
  }catch(error){status.textContent=error.message;button.disabled=false;}
 };
} catch(error) {status.textContent=error.status===401?i18nT('Uygulamaya giriş yaptıktan sonra bu sayfayı tekrar açın veya yukarıdaki e-posta yolunu kullanın.'):i18nT('Talep ekranına ulaşılamadı. E-posta üzerinden başvurabilirsiniz.');}
}
if(globalThis.document?.body?.classList.contains('account-page'))void mountAccountDeletion();
