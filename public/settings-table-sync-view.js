import {html,t} from './i18n.js';
import {esc} from './ui.js';

export const syncModes={
 incremental:{label:'Son aktarımdan itibaren al',help:'Yeni kayıtları alır. Daha önce listeden kaldırdığınız kayıtlar geri gelmez.'},
 full:{label:'En baştan al',help:'Pazaryerinin erişime açtığı geçmişi bu tablo için yeniden okur. Bu tablodan kaldırılmış ve yeniden alınabilen kayıtlar geri gelebilir.'}
};
const providerNames={hepsiburada:'Hepsiburada',trendyol:'Trendyol',n11:'n11',idefix:'idefix',pttavm:'PTT AVM',pazarama:'Pazarama',koctas:'Koçtaş'};
const sourceNames={orders:'Siparişler',returns:'İadeler',catalog:'Katalog',questions:'Sorular',payouts:'Hakedişler'};
const states={queued:'Sırada',running:'Aktarılıyor',complete:'Tamamlandı',failed:'Tamamlanamadı'};
export const syncInProgress=run=>['queued','running'].includes(run?.state);
export const supportedModes=target=>Object.keys(syncModes).filter(mode=>target?.modes?.includes(mode));

export function tableSyncPanel(company){
 return html`<section class="settings-table-sync"><header><h3>Veri Aktarımı</h3><p class="settings-note">Mağaza · <strong>${esc(company.name)}</strong></p></header>
 <div class="settings-sync-status"><div data-sync-run aria-live="polite" role="status">Aktarım durumu yükleniyor…</div><button class="btn" type="button" data-sync-refresh>Durumu yenile</button></div>
 <p class="form-error" data-sync-error role="alert" hidden></p><div data-sync-tables></div></section>`;
}
export function tableSyncList(tables){
 if(!tables.length)return html('<p class="settings-note">Bu mağazada kurulmuş canlı aktarım tablosu bulunmuyor.</p>');
 return html`<ul class="settings-sync-list">${tables.map(table=>html`<li><div><strong>${esc(table.label)}</strong><small>${esc((table.providers||[]).map(id=>providerNames[id]||id).join(', ')||t('Etkin bağlantı bulunmuyor.'))}</small></div><button class="btn" type="button" data-sync-target="${esc(table.key)}" aria-label="${esc(table.label)} · ${esc(t('Aktarım seçenekleri'))}" ${supportedModes(table).length?'':'disabled'}>Aktarım seçenekleri</button></li>`).join('')}</ul>`;
}
export function tableSyncChoice(table){
 const modes=supportedModes(table);
 const starts=Object.entries(table.fromDates||{}).filter(([,date])=>typeof date==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(date));
 return html`<form class="settings-sync-choice" data-sync-form><button type="button" class="text-btn" data-sync-back>‹ Tablolara dön</button><fieldset><legend>${esc(table.label)}</legend>
 ${modes.map(mode=>html`<label><input type="radio" name="syncMode" value="${mode}" required ${mode===modes[0]?'checked':''}><span><strong>${t(syncModes[mode].label)}</strong><small>${t(syncModes[mode].help)}</small></span></label>`).join('')}</fieldset>
 ${starts.length?html`<details><summary>Geçmiş aktarım başlangıçları</summary><ul class="settings-sync-limits">${starts.map(([id,date])=>html`<li>${esc(providerNames[id]||id)} · ${esc(date.split('-').reverse().join('.'))}</li>`).join('')}</ul></details>`:''}
 ${table.limits?.length?html`<ul class="settings-sync-limits">${table.limits.map(note=>html`<li>${esc(note)}</li>`).join('')}</ul>`:''}
 <button type="submit" class="btn primary">Aktarımı başlat</button></form>`;
}
export function tableSyncRun(run,tables){
 if(!run)return html('<span>Henüz aktarım istenmedi.</span>');
 const title=tables.find(row=>row.key===run.table)?.label||t(run.table?'Tablo aktarımı':'Mağaza aktarımı');
 const status=t(states[run.state]||'Durum doğrulanamadı');
 const sources=Array.isArray(run.sources)?run.sources:[];
 const failures=sources.filter(row=>row.state==='failed');
 const added=sources.reduce((sum,row)=>sum+(Number.isSafeInteger(row.added)&&row.added>=0?row.added:0),0);
 return html`<strong>${esc(title)} · ${esc(status)}</strong>${run.table&&syncModes[run.mode]?html`<small>${esc(t(syncModes[run.mode].label))}</small>`:''}${run.state==='complete'?html`<small>İşlenen yeni veya yeniden görünür kayıt: ${added}</small>`:''}
 ${failures.length?html`<ul>${failures.map(row=>{
  const [source,provider]=String(row.key).split(':');
  return html`<li>${esc(providerNames[provider]||provider||'')} · ${esc(t(sourceNames[source]||source))}: ${esc(row.error||t('Kaynak aktarımı tamamlanamadı.'))}</li>`;
 }).join('')}</ul>`:''}`;
}
