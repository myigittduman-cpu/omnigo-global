import {bindCredentialEditor} from './credential-editor.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,money,openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

const stamp=value=>value?esc(new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'})):'—';
export async function n11Connection(state,onSave){
 const company=state.company,token=workspaceToken(state);
 try{
  const data=await api(`/integrations/n11?company=${company}`);
  if(!sameWorkspace(state,token))return;
  const sync=data.sync||{},published=Boolean(sync.salesPublished),ready=sync.phase==='review_ready',rows=sync.preview?.packages||[];
  const companyName=state.companies.find(row=>row.id===Number(company))?.name||'';
  const form=i18nHtml`<form class="form-grid"><p class="form-help full-width">n11 satıcı panelindeki mevcut API anahtarını ve API şifresini girin. Bilgiler sunucuda şifrelenir. API anahtarını yeniden üretmeniz gerekmez.</p><label>API anahtarı (appKey)<input name="apiKey" type="password" autocomplete="off" required maxlength="2048"></label><label>API şifresi (appSecret)<input name="apiSecret" type="password" autocomplete="new-password" required maxlength="2048"></label><p class="form-error full-width" role="alert"></p><button class="btn primary" type="submit">Kaydet ve bağlantıyı kontrol et</button></form>`;
  const preview=ready?i18nHtml`<p>Son başarılı okuma: ${stamp(sync.checkedAt)}</p><p>API'nin bildirdiği mağaza numarası: <strong>${esc(data.sellerId||i18nT('Henüz belirlenemedi'))}</strong></p><p class="form-help">${stamp(sync.periodStart)} – ${stamp(sync.periodEnd)}. İlk sayfadan en fazla 100 paket kontrol edilir. Paket sayısı sipariş sayısı değildir. İptal ve iadeler henüz mutabık olmadığı için bu örnekler müşteri cirosuna eklenmez.</p>${rows.length?i18nHtml`<div class="table-scroll"><table><thead><tr><th>Sipariş / paket</th><th>Ürün adedi</th><th>API fatura tutarı</th></tr></thead><tbody>${rows.map(row=>i18nHtml`<tr><td>${esc(row.orderNumber)}<small style="display:block">${esc(row.packageId||i18nT('Paket numarası yok'))}</small></td><td>${row.quantity}</td><td>${money(row.invoiceCents)}</td></tr>`).join('')}</tbody></table></div>`:i18nHtml('<p>Bu aralıkta sipariş paketi dönmedi. Bu sonuç aylık satışın sıfır olduğunu göstermez.</p>')}`:'';
  const heading=published?i18nT('n11 satış aktarımı aktif.'):ready?i18nT('Bağlantı doğrulandı; satış aktarımı hazırlanıyor.'):data.configured?i18nT('Bağlantı kontrolü sırada.'):i18nT('n11 mağazanızı bağlayın.');
  const dialog=openDialog(i18nT('n11 bağlantısı'),i18nHtml`<p>Seçili müşteri: <strong>${esc(companyName)}</strong></p><p><strong>${heading}</strong></p>${data.job?.error?i18nHtml`<p class="form-error">${esc(data.job.error)}</p>`:''}${sync.failure?i18nHtml`<p class="form-error">${esc(sync.failure.message)}</p><p class="form-help">Kontrol kodu: ${esc(sync.failure.code)}</p>`:''}${published?i18nHtml`<p>Son başarılı aktarım: ${stamp(sync.lastSuccessAt)}</p><p class="form-help">Siparişler ${stamp(sync.startDate)} tarihinden itibaren aktarılıyor. Satış, sipariş ve ürün bilgileri iki portalda da gösterilir. Güncelleme tamamlanırken son başarılı veriler korunur.</p>`:preview}${data.configured?i18nHtml`<p class="form-help">${!ready&&data.job?.state==='pending'?'Sonraki kontrol: '+stamp(new Date(data.job.next_run*1000).toISOString()):''}</p><div class="support-toolbar"><button type="button" class="btn" data-n11-refresh>Durumu yenile</button><button type="button" class="btn" data-n11-retry>Aktarımı tekrar dene</button></div><details><summary>Kayıtlı API bilgileri</summary>${form}</details>`:form}<p class="form-help">Sipariş ve iade sayfaları tamamlandığında satışlar yönetici ve İş Ortağım’da otomatik güncellenir. Stok, fiyat ve sipariş durumları değiştirilmez.</p>`);
  const credentials=dialog.querySelector('form');
  bindCredentialEditor(credentials,{provider:'n11',company,status:data,current:()=>sameWorkspace(state,token)});
  dialog.addEventListener('close',()=>credentials.reset(),{once:true});
  credentials.onsubmit=async event=>{
   event.preventDefault();if(!sameWorkspace(state,token))return;
   const button=credentials.querySelector('[type=submit]');button.disabled=true;
   try{
    await api(`/integrations/n11?company=${company}`,'POST',{apiKey:credentials.elements.apiKey.value.trim(),apiSecret:credentials.elements.apiSecret.value.trim(),version:data.version});
    credentials.reset();dialog.close();if(!sameWorkspace(state,token))return;
    await onSave();toast(i18nT('n11 bağlantı bilgileri kaydedildi. Okuma kontrolü sırada.'));await n11Connection(state,onSave);
   }catch(error){credentials.querySelector('.form-error').textContent=error.message;}finally{button.disabled=false;}
  };
  dialog.querySelector('[data-n11-refresh]')?.addEventListener('click',()=>{dialog.close();if(sameWorkspace(state,token))n11Connection(state,onSave);});
  dialog.querySelector('[data-n11-retry]')?.addEventListener('click',async event=>{
   const button=event.currentTarget;button.disabled=true;
   try{if(!sameWorkspace(state,token))return;await api(`/integrations/n11?company=${company}`,'POST',{action:'retry'});dialog.close();await onSave();await n11Connection(state,onSave);}
   catch(error){toast(error.message);}finally{button.disabled=false;}
  });
 }catch(error){toast(error.message);}
}
