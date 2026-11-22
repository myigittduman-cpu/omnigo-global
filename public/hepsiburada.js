import {bindCredentialEditor} from './credential-editor.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,money,openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

const stamp=value=>value?esc(new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'})):'—';
export async function hepsiburadaConnection(state,onSave){
 const company=state.company,token=workspaceToken(state);
 try{
  const data=await api(`/integrations/hepsiburada?company=${company}`);
  if(!sameWorkspace(state,token))return;
  const sync=data.sync||{},live=Boolean(sync.ordersPublished),ready=sync.phase==='review_ready',rows=sync.preview?.items||[];
  const companyName=state.companies.find(row=>row.id===Number(company))?.name||'';
  const form=i18nHtml`<form class="form-grid"><p class="form-help full-width">Hepsiburada API erişim bilgilerini girin. Mağaza giriş şifresi kullanılmaz. Bilgiler sunucuda şifrelenir.</p><label>Merchant ID (mağaza numarası)<input name="merchantId" value="${esc(data.merchantId||'')}" autocomplete="off" required maxlength="36" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label><label>API kullanıcı adı<input name="username" autocomplete="off" required maxlength="200"></label><label>Servis anahtarı / API şifresi<input name="serviceKey" type="password" autocomplete="new-password" required maxlength="2048"></label><label>Entegratör adı (User-Agent)<input name="userAgent" autocomplete="off" required maxlength="200"></label><p class="form-help full-width">Bu erişime ait API kullanıcı adı ve User-Agent bilgisini kullanın. Mevcut Entegra anahtarını yenilemeyin veya yetkisini kaldırmayın.</p><p class="form-error full-width" role="alert"></p><button class="btn primary" type="submit">Kaydet ve bağlantıyı kontrol et</button></form>`;
  const preview=ready?i18nHtml`<p>Son başarılı okuma: ${stamp(sync.checkedAt)}</p><p>Mağaza eşleşmesi: <strong>${sync.merchantVerified?i18nT('Doğrulandı'):i18nT('Henüz sipariş üzerinden doğrulanamadı')}</strong></p><p class="form-help">İlk 10 paketlenecek sipariş kalemi kontrol edilir. Bu örnekler aylık satış toplamını göstermez; paketlenmiş, teslim edilmiş, iptal ve iade kayıtları ayrıca alınmalıdır.</p>${rows.length?i18nHtml`<div class="table-scroll"><table><thead><tr><th>Sipariş / ürün</th><th>Adet</th><th>Kalem tutarı</th></tr></thead><tbody>${rows.map(row=>i18nHtml`<tr><td>${esc(row.orderNumber)}<small style="display:block">${esc(row.title)}</small></td><td>${row.quantity}</td><td>${money(row.amountCents)}</td></tr>`).join('')}</tbody></table></div>`:i18nHtml('<p>Paketlenecek sipariş dönmedi. Bu sonuç aylık satışın sıfır olduğu anlamına gelmez.</p>')}`:'';
  const dialog=openDialog(i18nT('Hepsiburada bağlantısı'),i18nHtml`<p>Seçili müşteri: <strong>${esc(companyName)}</strong></p><p><strong>${live?i18nT('Canlı sipariş aktarımı açık.'):ready?i18nT('API okuma kontrolü tamamlandı.'):data.configured?i18nT('Bağlantı kontrolü sırada.'):i18nT('Hepsiburada mağazanızı bağlayın.')}</strong></p>${data.job?.error?i18nHtml`<p class="form-error">${esc(data.job.error)}</p>`:''}${sync.failure?i18nHtml`<p class="form-error">${esc(sync.failure.message)}</p><p class="form-help">Kontrol kodu: ${esc(sync.failure.code)}</p>`:''}${live?i18nHtml`<p>Son tamamlanan kontrol: ${stamp(sync.lastSuccessAt)}</p><p>Yeni siparişler, kargo ve iptal durumları otomatik kontrol edilir. İlk geçmiş aktarımı tamamlanırken kayıtlar sırayla görünür.</p>`:''}${preview}${data.configured?i18nHtml`<div class="support-toolbar"><button type="button" class="btn" data-hb-refresh>Durumu yenile</button><button type="button" class="btn" data-hb-retry>Okumayı tekrar dene</button></div><details><summary>Kayıtlı API bilgileri</summary>${form}</details>`:form}<p class="form-help">${sync.salesPublished?i18nText`Satış, iptal ve gerçekleşmiş ücret iadeleri iki panelin aylık özetine otomatik yansır. İadeler siparişin ait olduğu aydan düşülür. Son satış kontrolü: ${stamp(sync.salesLastSuccessAt)}`:i18nT('Sipariş aktarımı yalnızca veri okur. Aylık satış ve iade toplamları ayrıca doğrulanır; bu ekran satış mutabakatının tamamlandığı anlamına gelmez.')}</p>`);
  const credentials=dialog.querySelector('form');
  bindCredentialEditor(credentials,{provider:'hepsiburada',company,status:data,current:()=>sameWorkspace(state,token)});
  dialog.addEventListener('close',()=>credentials.reset(),{once:true});
  credentials.onsubmit=async event=>{
   event.preventDefault();if(!sameWorkspace(state,token))return;
   const button=credentials.querySelector('[type=submit]');button.disabled=true;
   try{
    const values=Object.fromEntries(['merchantId','username','serviceKey','userAgent'].map(name=>[name,credentials.elements[name].value.trim()]));
    await api(`/integrations/hepsiburada?company=${company}`,'POST',{...values,version:data.version});
    credentials.reset();dialog.close();if(!sameWorkspace(state,token))return;
    await onSave();toast(i18nT('Hepsiburada okuma kontrolü sıraya alındı.'));await hepsiburadaConnection(state,onSave);
   }catch(error){credentials.querySelector('.form-error').textContent=error.message;}finally{button.disabled=false;}
  };
  dialog.querySelector('[data-hb-refresh]')?.addEventListener('click',()=>{dialog.close();if(sameWorkspace(state,token))hepsiburadaConnection(state,onSave);});
  dialog.querySelector('[data-hb-retry]')?.addEventListener('click',async event=>{
   const button=event.currentTarget;button.disabled=true;
   try{if(!sameWorkspace(state,token))return;await api(`/integrations/hepsiburada?company=${company}`,'POST',{action:'retry'});dialog.close();await onSave();await hepsiburadaConnection(state,onSave);}
   catch(error){toast(error.message);}finally{button.disabled=false;}
  });
 }catch(error){toast(error.message);}
}
