import {bindCredentialEditor} from './credential-editor.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {pazaramaStatus} from './pazarama-status.js';

const stamp=value=>value?esc(new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'})):'—';

export async function pazaramaConnection(state,onSave){
 const company=state.company;
 try {
  const data=await api(`/integrations/pazarama?company=${company}`);
  const error=data.job?.error;
  const {heading,scheduled}=pazaramaStatus(data);
  const failure=data.sync?.failure;
  const diagnostic=failure?i18nHtml`<p class="form-error">${esc(failure.message)}</p><p class="form-help">Tanı kodu: ${esc(failure.code)} · Kontrol: ${stamp(failure.checkedAt)}</p>`:'';
  const preview=i18nHtml`<p>Son satış aktarımı: ${stamp(data.sync?.lastSuccessAt)}</p><p>Veri başlangıcı: ${stamp(data.sync?.startDate)}</p><p>Son soru aktarımı: ${stamp(data.questions?.sync?.lastSuccessAt)}</p>${data.questions?.job?.error?i18nHtml`<p class="form-error">Sorular: ${esc(data.questions.job.error)}</p>`:''}${data.questions?.sync?.failure?i18nHtml`<p class="form-error">${esc(data.questions.sync.failure.message)}</p>`:''}`;
  const form=i18nHtml`<form class="form-grid" id="pazaramaCredentials"><p class="form-help full-width">Pazarama veya Entegra üzerindeki anahtarları yenilemeyin. Bilgiler sunucuda şifreli saklanır.</p><label class="full-width">Satıcı ID<input name="sellerId" value="${esc(data.sellerId||'')}" required autocomplete="off" maxlength="36"></label><label>API Key<input name="apiKey" type="password" required autocomplete="off" maxlength="2048"></label><label>API Secret<input name="apiSecret" type="password" required autocomplete="new-password" maxlength="2048"></label><p class="form-error full-width" role="alert"></p><button type="submit" class="btn primary">Kaydet ve aktarımı başlat</button></form>`;
  const dialog=openDialog(i18nT('Pazarama bağlantı kontrolü'),i18nHtml`<p><strong>${heading}</strong></p>${data.sellerId?i18nHtml`<p>Girilen satıcı ID: ${esc(data.sellerId)} · ${data.sync?.sellerVerified?i18nT('Mağaza doğrulandı.'):i18nT('Doğrulama bekleniyor.')}</p>`:''}${error?i18nHtml`<p class="form-error">${esc(error)}</p>`:''}${diagnostic}${scheduled?i18nHtml`<p>Sonraki kontrol: ${stamp(new Date(Number(data.job.next_run)*1000).toISOString())}</p>`:''}${preview}${data.configured?i18nHtml('<button class="btn" id="pazaramaRefresh">Durumu yenile</button><button class="btn" id="pazaramaRetry">Aktarımı yeniden dene</button><details><summary>Kayıtlı API bilgileri</summary>')+form+i18nHtml('</details>'):form}<p class="form-help">Satışlar, ürünler ve ürün soruları otomatik aktarılır. Soru cevaplarını Pazarama panelinden gönderebilirsiniz.</p>`);
  const credentials=dialog.querySelector('form');
  bindCredentialEditor(credentials,{provider:'pazarama',company,status:data,current:()=>state.user?.role==='admin'&&Number(state.company)===Number(company)});
  dialog.addEventListener('close',()=>credentials.reset(),{once:true});
  credentials.onsubmit=async event=>{
   event.preventDefault();const button=credentials.querySelector('[type=submit]');button.disabled=true;
   try {
    await api(`/integrations/pazarama?company=${company}`,'POST',{sellerId:credentials.elements.sellerId.value.trim(),apiKey:credentials.elements.apiKey.value.trim(),apiSecret:credentials.elements.apiSecret.value.trim(),version:data.version});
    credentials.reset();dialog.close();await onSave();toast(i18nT('Satış ve soru aktarımı sıraya alındı.'));await pazaramaConnection(state,onSave);
   }catch(error){credentials.querySelector('.form-error').textContent=error.message;}
   finally{button.disabled=false;}
  };
  dialog.querySelector('#pazaramaRefresh')?.addEventListener('click',()=>{dialog.close();pazaramaConnection(state,onSave);});
  dialog.querySelector('#pazaramaRetry')?.addEventListener('click',async event=>{
   event.currentTarget.disabled=true;
   try{await api(`/integrations/pazarama?company=${company}`,'POST',{action:'retry'});dialog.close();await onSave();await pazaramaConnection(state,onSave);}
   catch(error){toast(error.message);event.target.disabled=false;}
  });
 }catch(error){toast(error.message);}
}
