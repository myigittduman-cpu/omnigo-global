import {esc} from './ui.js';
import {notificationTime} from './notification-time.js';
import {notificationFields} from './notification-detail-view.js';
import {marketplaceImportCatalogSummaryView} from './marketplace-import-catalog-summary.js';

const events=['marketplace_import_started','marketplace_import_completed','marketplace_import_failed'];
const states={queued:'Sırada',running:'Aktarım sürüyor',retry:'Yeniden denenecek',complete:'Aktarım tamamlandı',failed:'Aktarım tamamlanamadı',not_enabled:'Etkin değil',unsupported:'Desteklenmiyor'};
const stages={connection:'Bağlantı',orders:'Siparişler',products:'Ürün bilgileri',stock:'Stok bilgileri'};
const countLabels={products:'Ürün',stockRecords:'Stok kaydı',orders:'Sipariş',returns:'İade talebi'};
const stageStates={...states,queued:'Bekliyor',running:'İşleniyor',complete:'Tamamlandı',failed:'Tamamlanamadı'};
const snapshotStates={...states,complete:'Son aktarım kaydı mevcut',failed:'Bağlantı kontrol gerektiriyor',unknown:'Aktarım durumu doğrulanamadı'};
const knownCount=value=>Number.isSafeInteger(value)&&value>=0;
const countText=value=>value.toLocaleString('tr-TR');

export const isLiveImportSnapshot=report=>report?.kind==='live_snapshot';
export const isMarketplaceImportNotification=event=>!isLiveImportSnapshot(event)&&!isLiveImportSnapshot(event?.importReport)&&Array.isArray(event?.events)&&event.events.some(value=>events.includes(value));
export const marketplaceImportState=(state,kind='')=>(kind==='live_snapshot'?snapshotStates:states)[state]||(kind==='live_snapshot'?'Aktarım durumu doğrulanamadı':'Kontrol ediliyor');
export const marketplaceImportReport=event=>event?.importReport&&typeof event.importReport==='object'?event.importReport:event||{};
export function marketplaceImportTitle(event){
 if(!isMarketplaceImportNotification(event))return '';
 if(event.events.includes('marketplace_import_started'))return 'Pazaryeri aktarımı başlatıldı';
 const report=marketplaceImportReport(event);
 return report.state==='failed'||event.events.includes('marketplace_import_failed')?'Pazaryeri aktarımı tamamlanamadı':'Pazaryeri aktarımı tamamlandı';
}
export function marketplaceImportSummary(report){
 return Object.entries(countLabels).filter(([key])=>knownCount(report?.counts?.[key])).map(([key,label])=>`${countText(report.counts[key])} ${label.toLocaleLowerCase('tr-TR')}`).join(' · ');
}
function stageCount(stage){
 if(knownCount(stage?.count))return countText(stage.count);
 if(knownCount(stage?.counts))return countText(stage.counts);
 return marketplaceImportSummary(stage)||'';
}

/** Render only the safe report projection; no credentials, customer or order details. */
export function marketplaceImportReportView(event){
 const report=marketplaceImportReport(event),snapshot=isLiveImportSnapshot(report),stageEntries=Object.entries(stages).filter(([key])=>report.stages?.[key]),showStageCounts=stageEntries.some(([key])=>stageCount(report.stages[key]));
 const stageRows=stageEntries.map(([key,label])=>{
  const stage=report.stages[key],status=typeof stage==='string'?stage:stage.status||stage.state;
  const labelState=snapshot&&status==='complete'?'Son başarılı kayıt mevcut':stageStates[status]||(snapshot?'Doğrulanamadı':'Kontrol ediliyor');
  return `<tr><th scope="row">${esc(label)}</th><td>${esc(labelState)}</td>${showStageCounts?`<td>${esc(stageCount(stage)||'—')}</td>`:''}</tr>`;
 }).join('');
 const fields=Object.entries(countLabels).filter(([key])=>knownCount(report.counts?.[key])).map(([key,label])=>[label,countText(report.counts[key])]);
 const status=marketplaceImportState(report.state,report.kind),warnings=Array.isArray(report.warnings)?report.warnings.filter(value=>typeof value==='string'&&value.trim()):[];
 if(typeof report.warning==='string'&&report.warning.trim())warnings.push(report.warning);
 if(typeof report.metadata?.warning==='string'&&report.metadata.warning.trim())warnings.push(report.metadata.warning);
 const terminal=['complete','failed'].includes(report.state),footnote=snapshot||report.countsScope==='current_snapshot'?'Sayılar, bu pazaryeri için şu anda kayıtlı toplamları gösterir; yeni aktarılan adet değildir.':report.countsScope==='persisted_snapshot'?'Sayılar, aktarım sonunda bu pazaryeri için kayıtlı toplamları gösterir.':terminal?'Bu özet, ilgili aktarım çalışmasının sonucunu gösterir.':'Aktarım sunucuda devam eder. Ekranı kapatmanız işlemi durdurmaz.';
 const time=snapshot?report.updatedAt:report.finishedAt||report.updatedAt||event.createdAt,emptyCounts=snapshot?'Kayıt sayıları bu bağlantı için doğrulanamadı.':terminal?'Bu çalışma için kesin kayıt sayısı bildirilmedi.':'Kayıt sayıları henüz kesinleşmedi.';
 return `<section class="marketplace-import-report"><div class="notification-detail-summary ${report.state==='failed'?'import-failed':report.state==='complete'&&!snapshot?'import-complete':''}"><p><strong>${esc(report.providerLabel||event.provider||report.provider||'Pazaryeri')}</strong> · ${esc(status)}</p>${notificationTime(time)}</div>${fields.length?notificationFields(fields):`<p class="muted">${emptyCounts}</p>`}${report.error?`<p class="marketplace-import-note import-failed" role="alert">${esc(report.error)}</p>`:''}${report.catalogSummary||marketplaceImportCatalogSummaryView(report)?`<details class="import-catalog-disclosure"><summary>Katalog özeti ve kayıt ayrıntıları</summary>${marketplaceImportCatalogSummaryView(report)}</details>`:''}${stageRows?`<div class="marketplace-import-stages"><table aria-label="Aktarım aşamaları"><thead><tr><th scope="col">Aşama</th><th scope="col">Durum</th>${showStageCounts?'<th scope="col">Kayıt</th>':''}</tr></thead><tbody>${stageRows}</tbody></table></div>`:''}${[...new Set(warnings)].map(warning=>`<p class="marketplace-import-note">${esc(warning)}</p>`).join('')}${report.state==='retry'&&report.nextRetryAt?`<p class="marketplace-import-note">Sonraki kontrol: ${notificationTime(report.nextRetryAt)}</p>`:''}<p class="marketplace-import-footnote">${esc(footnote)}</p></section>`;
}
