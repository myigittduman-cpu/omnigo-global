import test from 'node:test';
import assert from 'node:assert/strict';
import {marketplaceImportCatalogSummaryView} from '../public/marketplace-import-catalog-summary.js';
import {marketplaceImportReportView} from '../public/marketplace-import-report.js';

const summary={scope:'current_provider_catalog',provider:'n11',providerLabel:'n11',sourceState:'verified',sourceCheckedAt:'2026-11-08T09:00:00Z',catalogRecords:6,products:5,saleActiveRecords:4,saleInactiveRecords:1,saleUnknownRecords:1,saleActiveProducts:3,saleInactiveProducts:1,saleUnknownProducts:1,incompleteRecords:2,missingNameRecords:1,unknownStockRecords:1,unknownSaleStatusRecords:1,invalidEvidenceRecords:0,staleRecords:0};
const report={provider:'n11',providerLabel:'n11',state:'complete',counts:{products:999,orders:24},currentCatalogSummary:summary};

test('Her pazaryeri raporu kendi katalog, açık ürün ve eksik kayıt adetlerini gösterir',()=>{
 const html=marketplaceImportCatalogSummaryView(report);
 assert.match(html,/n11 ürün özeti/);assert.match(html,/Aktarılan katalog kaydı<\/dt><dd>6/);
 assert.match(html,/Satışa açık kayıt<\/dt><dd>4/);assert.match(html,/Eksik \/ doğrulanamayan kayıt<\/dt><dd>2/);
 assert.match(html,/Tekilleştirilmiş ürün<\/dt><dd>5/);assert.match(html,/Satışa kapalı kayıt<\/dt><dd>1/);
 assert.match(html,/Durumu doğrulanamayan kayıt<\/dt><dd>1/);assert.match(html,/aynı ürün bir kez sayılır/);
 assert.match(html,/Kaynak kanıtı okunamadı<\/dt><dd>0/);
 assert.match(html,/Ürün adı eksik<\/dt><dd>1/);
 assert.match(html,/datetime="2026-11-08T09:00:00.000Z"/);
});

test('Başka sağlayıcının özeti, eksik özet ve farklı kapsam rapora karışmaz',()=>{
 for(const value of [{}, {...report,currentCatalogSummary:null}, {...report,currentCatalogSummary:{...summary,provider:'hepsiburada'}}, {...report,currentCatalogSummary:{...summary,scope:'all_marketplaces'}}])assert.equal(marketplaceImportCatalogSummaryView(value),'');
});

test('Bilinmeyen kaynak durumu ve sayılar sıfır başarıya dönüştürülmez',()=>{
 const empty=Object.fromEntries(Object.entries(summary).map(([key,value])=>[key,typeof value==='number'?null:value]));
 const html=marketplaceImportCatalogSummaryView({...report,currentCatalogSummary:{...empty,sourceState:'unverified',sourceCheckedAt:null}});
 assert.match(html,/Doğrulanamadı/);assert.match(html,/açık veya kapalı olduğu tahmin edilmez/);
 assert.doesNotMatch(html,/<dd>0|<time|kontrolüne göre doğrulandı/);
 const zero=marketplaceImportCatalogSummaryView({...report,currentCatalogSummary:{...summary,catalogRecords:0,products:0,saleActiveProducts:0,saleActiveRecords:0,incompleteRecords:0}});
 assert.match(zero,/Aktarılan katalog kaydı<\/dt><dd>0/);assert.match(zero,/Satışa açık kayıt<\/dt><dd>0/);
});

test('Kanonik ürün adedi eksik kayıt sayımının yerine kullanılmaz',()=>{
 const legacy={...summary};delete legacy.saleActiveRecords;delete legacy.saleInactiveRecords;delete legacy.saleUnknownRecords;
 const html=marketplaceImportCatalogSummaryView({...report,currentCatalogSummary:legacy});
 assert.match(html,/Satışa açık kayıt<\/dt><dd class="is-unknown">Doğrulanamadı/);
 assert.doesNotMatch(html,/Satışa açık kayıt<\/dt><dd>3/);
});

test('Yeni ve tarihsel rapor aynı güncel katalog bileşenini kullanır; eski sayılar değiştirilmez',()=>{
 const before=structuredClone(report);
 for(const value of [report,{...report,kind:'live_snapshot',countsScope:'current_snapshot'}]){
  const html=marketplaceImportReportView(value);assert.match(html,/Pazaryeri ürün özeti/);assert.match(html,/Aktarılan katalog kaydı<\/dt><dd>6/);
  assert.match(html,/<dd>999<\/dd>/);assert.match(html,/yalnız bu pazaryerinin güncel kayıtlı kataloğudur/);
 }
 assert.deepEqual(report,before);
});

test('Özet yalnız güvenli adet alanlarını gösterir; ham API hatası ve bilgi sızdırmaz',()=>{
 const html=marketplaceImportCatalogSummaryView({...report,currentCatalogSummary:{...summary,providerLabel:'<img onerror=bad>',apiKey:'GİZLİ ANAHTAR',sourceError:'HTTP 401',incompleteRecords:1}});
 assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img|GİZLİ ANAHTAR|HTTP 401/);
 assert.match(html,/Eksik \/ doğrulanamayan kayıt<\/dt><dd>1/);
});
