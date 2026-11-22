import {esc} from './ui.js';
import {marketplaceImportState,marketplaceImportSummary,isLiveImportSnapshot} from './marketplace-import-report.js';
import {isActiveConnectionImport} from './inventory-refresh-policy.js';

const visibleStates=new Set(['queued','running','retry','complete','failed']);

function snapshotRow(run){
 const active=isActiveConnectionImport(run),summary=marketplaceImportSummary(run),counts=summary?'Kayıtlı toplam: '+summary:'Kayıt sayıları doğrulanamadı.';
 return `<article class="inventory-connection-snapshot ${run.state==='failed'?'import-failed':''}" data-import-run="${esc(run.runId)}"><div class="inventory-snapshot-summary"><strong>${esc(run.providerLabel||run.provider||'Pazaryeri')}</strong><span class="inventory-connection-state" role="status" title="${esc(marketplaceImportState(run.state,run.kind))}">${esc(marketplaceImportState(run.state,run.kind))}</span><small title="${esc(counts)}">${esc(counts)}</small></div><button type="button" class="btn secondary" data-inventory-import-report="${esc(run.runId)}" aria-label="${esc(run.providerLabel||run.provider||'Pazaryeri')} aktarım durum raporunu aç">Rapor</button><div class="inventory-snapshot-progress">${active?'<progress aria-label="Pazaryeri aktarımı sürüyor"></progress>':''}</div></article>`;
}

export function inventoryConnectionImportsView(data,admin){
 if(!admin||!Array.isArray(data?.connectionImports))return '';
 const runs=data.connectionImports.filter(run=>run&&(visibleStates.has(run.state)||isLiveImportSnapshot(run)&&run.state==='unknown'));
 if(!runs.length)return '';
 const snapshots=runs.filter(isLiveImportSnapshot),imports=runs.filter(run=>!isLiveImportSnapshot(run));
 return `<section class="inventory-import inventory-connection-imports" aria-label="Pazaryeri bağlantı aktarımı"><div class="inventory-import-heading"><div><strong>${snapshots.length?'Mevcut bağlantıların aktarım durumu':'Pazaryeri aktarım durumu'}</strong><p>${runs.some(isActiveConnectionImport)?'Bilgiler aktarılıyor. Ekranı kapatsanız da işlem devam eder.':snapshots.length?'Pazaryerlerinden alınmış son kayıtların durumu':'Son bağlantı aktarımının özeti'}</p></div></div>${imports.length?`<div class="inventory-connection-runs">${imports.map(run=>{
  const active=isActiveConnectionImport(run),snapshot=isLiveImportSnapshot(run),summary=marketplaceImportSummary(run),counts=summary?(['persisted_snapshot','current_snapshot'].includes(run.countsScope)?'Kayıtlı toplam: ':'')+summary:active?'Kayıt sayıları kesinleşiyor.':snapshot?'Kayıt sayıları bu bağlantı için doğrulanamadı.':'Aşama sonuçlarını aktarım raporundan inceleyin.';
  return `<article class="inventory-connection-run ${run.state==='failed'?'import-failed':run.state==='complete'&&!snapshot?'import-complete':''}" data-import-run="${esc(run.runId)}"><div><div class="inventory-connection-run-heading"><strong>${esc(run.providerLabel||run.provider||'Pazaryeri')}</strong><span class="inventory-connection-state" role="status">${esc(marketplaceImportState(run.state,run.kind))}</span></div><small class="inventory-import-counts">${esc(counts)}</small><small class="inventory-history-note inventory-import-run-note">${esc(run.error||'İşlem aşamalarını aktarım ayrıntılarından inceleyebilirsiniz.')}</small></div><div class="inventory-connection-progress">${active?'<progress aria-label="Pazaryeri aktarımı sürüyor"></progress>':''}</div><button type="button" class="btn secondary" data-inventory-import-report="${esc(run.runId)}">${active?'Aktarım ayrıntıları':snapshot?'Durum raporu':'Aktarım raporu'}</button></article>`;
 }).join('')}</div>`:''}${snapshots.length?`<div class="inventory-connection-snapshots">${snapshots.map(snapshotRow).join('')}</div>`:''}</section>`;
}
