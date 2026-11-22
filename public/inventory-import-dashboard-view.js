import {esc,icon} from './ui.js';
import {inventoryConnectionImportsView} from './inventory-connection-imports.js';
import {isActiveConnectionImport} from './inventory-refresh-policy.js';
import {isLiveImportSnapshot,marketplaceImportReportView} from './marketplace-import-report.js';
import {contentError} from './content-state.js';

const states=new Set(['queued','running','retry','complete','failed']);
export const inventoryImportRuns=data=>(Array.isArray(data?.connectionImports)?data.connectionImports:[]).filter(run=>run&&(states.has(run.state)||isLiveImportSnapshot(run)&&run.state==='unknown'));
export function inventoryImportCounts(data){
 const runs=inventoryImportRuns(data),providers=new Set(runs.map(run=>String(run.provider||run.providerLabel||'').trim().toLocaleLowerCase('tr-TR')).filter(Boolean));
 return {connections:providers.size,active:new Set(runs.filter(isActiveConnectionImport).map(run=>run.provider||run.providerLabel||run.runId)).size};
}
export function inventoryImportDashboardButton(data,admin){
 if(!admin)return '';
 const counts=inventoryImportCounts(data);
 return `<button type="button" class="btn secondary inventory-import-dashboard-button" data-inventory-import-dashboard ${data?'':'disabled aria-busy="true"'}>${icon('reports')}<span>Aktarım raporları</span>${counts.connections?`<span class="inventory-import-dashboard-count" aria-label="${counts.connections} pazaryeri bağlantısı">${counts.connections}</span>`:''}${counts.active?`<span class="inventory-import-dashboard-active">${counts.active} aktif</span>`:''}</button>`;
}
export function inventoryMetadataReportView(data){
 const metadata=data?.metadata;if(!Number.isSafeInteger(metadata?.pending)||metadata.pending<0)return '';
 return `<section class="inventory-import-metadata" aria-label="Ürün bilgisi aktarımı"><strong>${metadata.pending?'Ürün bilgileri tamamlanıyor':'Ürün bilgisi kontrolü'}</strong><p>${metadata.pending?esc(metadata.pending)+' stoklu ürünün adı kontrol ediliyor; canlı stok aktarımı devam ediyor.':'Bekleyen ürün bilgisi bulunmuyor.'}</p><small title="${esc(metadata.error||'')}">${esc(metadata.error||'')}</small></section>`;
}
const connectionReserve='<div class="inventory-import-reserve" aria-hidden="true"><span>Yeni bağlantılar için ayrılan alan</span><div class="inventory-import-reserved-row"></div><div class="inventory-import-reserved-row"></div><div class="inventory-import-reserved-row"></div></div>';
export function inventoryImportDashboardView(data,{selectedId=null,detail=null,error=''}={}){
 const run=selectedId==null?null:inventoryImportRuns(data).find(item=>String(item.runId)===String(selectedId));
 const counts=inventoryImportCounts(data),back=run?'<button type="button" class="btn secondary" data-inventory-import-back>← Aktarım raporlarına dön</button>':'';
 const report=detail?.importReport?{...detail,importReport:{...detail.importReport,currentCatalogSummary:run?.currentCatalogSummary??detail.importReport.currentCatalogSummary}}:run;
 const content=run?marketplaceImportReportView(report):inventoryMetadataReportView(data)+(inventoryConnectionImportsView(data,true)||'<p class="muted">Bu müşteriye ait aktarım raporu bulunmuyor.</p>')+connectionReserve;
 return `<div class="inventory-import-dashboard-nav">${back}<p>${run?esc(run.providerLabel||run.provider||'Pazaryeri')+' · Aktarım ayrıntıları':counts.connections?`${counts.connections} pazaryeri bağlantısı${counts.active?' · '+counts.active+' aktif aktarım':''}`:'Pazaryeri aktarım kayıtları'}</p><button type="button" class="btn secondary" data-inventory-import-refresh>Yenile</button></div><div data-inventory-import-dashboard-error>${error?contentError(error):''}</div><div data-inventory-import-dashboard-content>${content}</div>`;
}
