import {esc} from './ui.js';
import {notificationTime} from './notification-time.js';

const known=value=>Number.isSafeInteger(value)&&value>=0;
const number=value=>known(value)?value.toLocaleString('tr-TR'):'Doğrulanamadı';
const messages={verified:'Satış durumları son başarılı katalog kontrolüne göre doğrulandı.',unverified:'Güncel kaynak doğrulaması eksik. Satışa açık veya kapalı olduğu tahmin edilmez.',unavailable:'Bu pazaryerinin güncel katalog aktarımı henüz doğrulanamadı.'};

export function marketplaceImportCatalogSummaryView(report){
 const summary=report?.currentCatalogSummary;
 if(!summary||summary.scope!=='current_provider_catalog'||summary.provider!==report.provider)return '';
 const metrics=[['Aktarılan katalog kaydı',summary.catalogRecords],['Satışa açık kayıt',summary.saleActiveRecords],['Eksik / doğrulanamayan kayıt',summary.incompleteRecords]];
 const secondary=[['Satışa kapalı kayıt',summary.saleInactiveRecords],['Durumu doğrulanamayan kayıt',summary.saleUnknownRecords],['Tekilleştirilmiş ürün',summary.products]];
 const quality=[['Ürün adı eksik',summary.missingNameRecords],['Stok bilgisi eksik',summary.unknownStockRecords],['Satış durumu eksik',summary.unknownSaleStatusRecords],['Kaynak kanıtı okunamadı',summary.invalidEvidenceRecords],['Güncel kaynak kanıtı yok',summary.staleRecords]];
 return `<section class="marketplace-import-catalog-summary" aria-label="Pazaryeri ürün özeti"><h3>${esc(summary.providerLabel||report.providerLabel||report.provider)} ürün özeti</h3><dl class="import-catalog-metrics">${metrics.map(([label,value])=>`<div><dt>${esc(label)}</dt><dd${known(value)?'':' class="is-unknown"'}>${esc(number(value))}</dd></div>`).join('')}</dl><dl class="import-catalog-secondary">${secondary.map(([label,value])=>`<div><dt>${esc(label)}</dt><dd>${esc(number(value))}</dd></div>`).join('')}</dl><p class="import-catalog-source">${esc(messages[summary.sourceState]||messages.unverified)}${summary.sourceCheckedAt?`<span>Son başarılı katalog kontrolü: ${notificationTime(summary.sourceCheckedAt)}</span>`:''}</p><details class="import-catalog-quality"><summary>Eksik kayıt ayrıntıları</summary><dl>${quality.map(([label,value])=>`<div><dt>${esc(label)}</dt><dd>${esc(number(value))}</dd></div>`).join('')}</dl><p>Aynı kayıtta birden fazla eksik olabilir; üstteki toplamda her kayıt bir kez sayılır.</p></details><p class="import-catalog-scope">Bu sayılar yalnız bu pazaryerinin güncel kayıtlı kataloğudur. Varyantlar ayrı katalog kayıtlarıdır; tekilleştirilmiş üründe aynı ürün bir kez sayılır.</p></section>`;
}
