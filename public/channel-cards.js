import {connectionMessage} from './status-label.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,platform,badge,empty} from './ui.js';
import {marketplaceSummary} from './marketplace-summary.js';
import {salesInspectionLink} from './sales-panels.js';

export function connectionHealth(channel){
 const h=channel.health||{status:channel.status==='inactive'?'inactive':'checking',message:i18nT('Bağlantı durumu kontrol ediliyor.')};
 const label={healthy:i18nT('Bağlantı sorunu yok'),warning:i18nT('Güncel aktarım bekleniyor'),error:i18nT('Bağlantı / aktarım sorunu'),checking:i18nT('Kontrol bekleniyor'),not_configured:i18nT('Bağlantı kurulmadı'),inactive:i18nT('Pazaryeri pasif')}[h.status]||i18nT('Kontrol bekleniyor');
 return i18nHtml`<div class="channel-health is-${esc(h.status)}" title="${esc(connectionMessage(h.message))}"><span aria-hidden="true"></span><strong>${label}</strong>${['error','warning'].includes(h.status)?i18nHtml`<small>${esc(connectionMessage(h.message))}</small>`:''}</div>`;
}
export function channelCards(state){
 const admin=state.user.role==='admin';
 const channels=state.data.channels.filter(channel=>admin||channel.status==='active');
 if(!channels.length)return empty(i18nT('Henüz aktif pazaryeri yok'),i18nT('Mağazalarınız tanımlandığında burada görünecek.'));
 return i18nHtml`<div class="channel-grid compact-channel-grid">${channels.map(channel=>{
  const summaryAvailable=channel.status==='active'&&state.data.marketplaceSales?.channels.some(item=>item.channel===channel.name&&item.available);
  const stamp=channel.lastSyncAt&&!summaryAvailable?new Date(channel.lastSyncAt):null;
  const sync=stamp&&!Number.isNaN(stamp.getTime())?i18nT('Son aktarım: ')+stamp.toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}):'';
  return i18nHtml`<article class="panel channel-card compact-channel-card"><header>${platform(channel.name)}${badge(channel.status)}</header><p class="channel-store-name">${esc(channel.storeName||i18nT('Mağaza adı henüz eklenmedi'))}</p>${connectionHealth(channel)}${channel.status==='active'?marketplaceSummary(state,channel.name):''}${salesInspectionLink(channel.name)}${sync?i18nHtml`<small class="channel-sync">${esc(sync)}</small>`:''}${admin?i18nHtml`<div class="channel-manage"><button class="text-btn" data-edit="channels" data-id="${channel.id}">${channel.storeName?i18nT('Mağaza bilgilerini düzenle'):i18nT('Mağaza adı ekle')}</button>${['PTT AVM','idefix','Trendyol'].includes(channel.name)?i18nHtml`<details><summary>Bağlantı işlemleri</summary><button class="btn" data-connect="${channel.name==='Trendyol'?'trendyol':channel.name==='idefix'?'idefix':'pttavm'}">${esc(channel.name)} bağlantı bilgileri</button></details>`:channel.name==='Koçtaş'?i18nHtml('<details><summary>Bağlantı işlemleri</summary><button class="btn" data-connect="koctas">Koçtaş bağlantı bilgileri</button><button class="btn" data-connect="koctas-check">Sipariş ve tutar kontrolü</button></details>'):channel.name==='Hepsiburada'?i18nHtml('<details><summary>Bağlantı işlemleri</summary><button class="btn" data-connect="hepsiburada">Hepsiburada bağlantı bilgileri</button></details>'):channel.name==='n11'?i18nHtml('<details><summary>Bağlantı işlemleri</summary><button class="btn" data-connect="n11">n11 bağlantı bilgileri</button></details>'):channel.name==='Pazarama'?i18nHtml('<details><summary>Bağlantı işlemleri</summary><button class="btn" data-connect="pazarama">Pazarama bağlantı kontrolü</button></details>'):''}</div>`:''}</article>`;
 }).join('')}</div>`;
}
