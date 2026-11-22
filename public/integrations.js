import {bindCredentialEditor} from './credential-editor.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

export async function koctasConnection(state,onSave){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state),url=`/integrations/koctas?company=${token.company}`;
 try{
  const data=await api(url);if(!sameWorkspace(state,token))return;
  const form=i18nHtml`<form class="form-grid"><label>Mağaza numarası (Shop ID)<input name="shopId" type="number" min="1" step="1" value="${esc(data.shopId||'')}" ${data.configured?'readonly':''} required autocomplete="off"></label><label>API anahtarı<input name="apiKey" type="password" required autocomplete="new-password" maxlength="36"></label><p class="form-help full-width">Koçtaş satıcı panelindeki mağaza numarası ve mevcut API anahtarını girin. Şifreli kaydedilir; yetki kontrolü ve sipariş aktarımı otomatik kuyruğa alınır. İlk aktarım bu ayın kayıtlarından başlar. Stok, fiyat ve sipariş durumları değiştirilmez.</p><p class="form-error full-width" role="alert"></p><button type="submit" class="btn primary">Kaydet ve aktarımı başlat</button></form>`;
  const stamp=data.sync?.lastSuccessAt?new Date(data.sync.lastSuccessAt).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}):i18nT('Henüz başarılı aktarım yok');
  const dialog=openDialog(i18nT('Koçtaş bağlantısı'),i18nHtml`<p>Seçili müşteri: <strong>${esc(state.companies.find(c=>Number(c.id)===Number(token.company))?.name||'')}</strong></p><p>Son başarılı aktarım: ${esc(stamp)}</p>${data.job?.error?i18nHtml`<p class="form-error">${esc(data.job.error)}</p>`:''}${data.configured?i18nHtml`<p>Mağaza numarası: ${esc(data.shopId)}</p><div class="support-toolbar"><button class="btn" data-refresh>Durumu yenile</button><button class="btn" data-retry>Aktarımı yeniden dene</button></div><details><summary>Kayıtlı API bilgileri</summary>${form}</details>`:form}<p class="form-help">Sipariş ve iptal durumları yaklaşık bir dakikalık aralıklarla kontrol edilir. Hakedişler yalnızca sizin elle kaydettiğiniz bilgilerden oluşur.</p>`);
  const credentials=dialog.querySelector('form');
  bindCredentialEditor(credentials,{provider:'koctas',company:token.company,status:data,current:()=>sameWorkspace(state,token),lockedFields:data.configured?['shopId']:[]});dialog.addEventListener('close',()=>credentials.reset(),{once:true});
  credentials.onsubmit=async event=>{
   event.preventDefault();if(!sameWorkspace(state,token))return;const button=credentials.querySelector('[type=submit]');button.disabled=true;
   try{await api(url,'POST',{shopId:Number(credentials.elements.shopId.value),apiKey:credentials.elements.apiKey.value.trim(),version:data.version});credentials.reset();dialog.close();if(!sameWorkspace(state,token))return;await onSave();toast(i18nT('Koçtaş bağlantı kontrolü sıraya alındı.'));await koctasConnection(state,onSave);}
   catch(error){credentials.querySelector('[role=alert]').textContent=error.message;}finally{button.disabled=false;}
  };
  dialog.querySelector('[data-refresh]')?.addEventListener('click',()=>{dialog.close();if(sameWorkspace(state,token))void koctasConnection(state,onSave);});
  dialog.querySelector('[data-retry]')?.addEventListener('click',async event=>{
   const button=event.currentTarget;button.disabled=true;
   try{if(!sameWorkspace(state,token))return;await api(url,'POST',{action:'retry'});dialog.close();if(!sameWorkspace(state,token))return;await onSave();await koctasConnection(state,onSave);}
   catch(error){toast(error.message);}finally{button.disabled=false;}
  });
 }catch(error){if(sameWorkspace(state,token))toast(error.message);}
}
