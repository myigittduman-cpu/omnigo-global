import {api} from './api.js';
import {scrollTableStart} from './table-page-scroll.js';
import {html,t,currentLocale} from './i18n.js';
import {esc} from './ui.js';
const stamp=value=>value?new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',dateStyle:'short',timeStyle:'short'}):t('Bilgi yok');
export function settingsActivityView(kind){
 return html`<div data-settings-activity="${kind}"><h3>${kind==='history'?t('İşlem geçmişi'):t('Açık oturumlar')}</h3><p class="settings-note">${kind==='history'?t('Hesap, rol ve yetki değişikliklerinde işlemi yapan kişi ve zaman bilgisi kayıt altındadır.'):stateNote()}</p><label class="settings-field settings-activity-search">Listede ara<input type="search" data-activity-query autocomplete="off" placeholder="Ad veya işlem ara"></label><p data-activity-status role="status"></p><div data-activity-list></div><div class="account-directory-pages"><button class="btn" type="button" data-activity-prev>Önceki</button><span data-activity-page class="pagination-current" aria-current="page"></span><button class="btn" type="button" data-activity-next>Sonraki</button></div></div>`;
}
const stateNote=()=>t('Mevcut oturumunuz ve kullanıcıların son giriş zamanı gösterilir. Her hesap yalnız bir kez listelenir.');
export function bindSettingsActivity(root,state,{current}){
 if(!root)return;const kind=root.dataset.settingsActivity,endpoint=kind==='history'?'/account/activity':'/account/sessions';let page=1,sequence=0,timer,busy=false,data;
 const valid=()=>root.isConnected&&current();
 const status=root.querySelector('[data-activity-status]'),search=root.querySelector('[data-activity-query]');
 async function refresh({scrollToTable=false}={}){
  const request=++sequence;status.textContent=t('Kayıtlar yükleniyor…');
  try{const result=await api(endpoint+'?'+new URLSearchParams({page,q:search.value}));if(!valid()||request!==sequence)return;data=result;
   root.querySelector('[data-activity-list]').innerHTML=kind==='history'?historyTable(result.items):sessionsTable(result.items);
   root.querySelector('[data-activity-page]').textContent=t('Sayfa')+' '+page;root.querySelector('[data-activity-prev]').disabled=page<=1;root.querySelector('[data-activity-next]').disabled=!result.hasMore;status.textContent=t('Güncel kayıtlar gösteriliyor.');
   if(scrollToTable)scrollTableStart(root,'.settings-activity-scroll');
  }catch(error){if(valid()&&request===sequence)status.textContent=error.message;}
 }
 search.oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>{if(valid()){page=1;void refresh();}},250);};
 root.onclick=async event=>{const button=event.target.closest('button');if(!button||busy||!valid())return;
  if(button.hasAttribute('data-session-revoke')){busy=true;button.disabled=true;try{await api('/account/sessions/revoke','POST',{id:button.dataset.sessionRevoke});if(valid())await refresh();}catch(error){if(valid())status.textContent=error.message;}finally{busy=false;if(button.isConnected)button.disabled=false;}return;}
  if(button.hasAttribute('data-activity-prev')&&page>1){page--;void refresh({scrollToTable:true});}
  if(button.hasAttribute('data-activity-next')&&data?.hasMore){page++;void refresh({scrollToTable:true});}
 };
 root.closest('dialog')?.addEventListener('close',()=>clearTimeout(timer),{once:true});void refresh();
}
function historyTable(items){
 return html`<div class="settings-activity-scroll"><table class="settings-activity-table"><thead><tr><th>Tarih / saat</th><th>İşlemi yapan</th><th>Kullanıcı / mağaza</th><th>Yapılan işlem</th></tr></thead><tbody>${items.map(item=>html`<tr><td>${esc(stamp(item.createdAt))}</td><td>${esc(item.actor)}</td><td>${esc(item.target)}</td><td>${esc(t(item.action))}</td></tr>`).join('')||html`<tr><td colspan="4">Henüz kayıt bulunmuyor.</td></tr>`}</tbody></table></div>`;
}
function sessionsTable(items){
 return html`<div class="settings-activity-scroll"><table class="settings-activity-table"><thead><tr><th>Hesap</th><th>Son giriş</th><th>Oturum</th></tr></thead><tbody>${items.map(item=>html`<tr><td><strong>${esc(item.name)}</strong><small>${esc(item.company||t('Omnigo hesabı'))}</small></td><td>${item.lastLogin?esc(stamp(item.lastLogin)):html`<span class="form-help">Giriş zamanı henüz kaydedilmedi</span>`}</td><td>${item.current?html`<span class="settings-current-session">Bu cihazdaki aktif oturum</span>`:'—'}</td></tr>`).join('')||html`<tr><td colspan="3">Kayıt bulunmuyor.</td></tr>`}</tbody></table></div>`;
}
