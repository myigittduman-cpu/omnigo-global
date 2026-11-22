import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryView} from '../public/inventory-view.js';
import {inventoryImportDashboardButton,inventoryImportCounts,inventoryImportDashboardView} from '../public/inventory-import-dashboard-view.js';

const run=(provider,state='complete')=>({runId:'snapshot-'+provider,kind:'live_snapshot',provider,providerLabel:provider,state,counts:{products:12,stockRecords:13},stages:{stock:{status:state}}});
const data={settings:{mode:'horoz'},activation:{marketplaceActive:true},initialImport:{complete:true},items:[],providers:['Trendyol','n11'],connectionImports:[run('Trendyol','running'),run('n11'),{...run('Trendyol','retry'),kind:undefined,runId:'historical'}]};
test('Aktarım raporu başlık eylemidir; filtre veya tablo içinde ayrı satır oluşturmaz',()=>{
 const html=inventoryView({admin:true,query:'',filter:'all',data});
 assert.doesNotMatch(html,/data-inventory-import-dashboard|inventory-report-actions|Mevcut bağlantıların aktarım durumu|data-inventory-import-report=/);
 const button=inventoryImportDashboardButton(data,true);assert.match(button,/2 pazaryeri bağlantısı/);assert.match(button,/1 aktif/);
 assert.match(inventoryImportDashboardButton(null,true),/disabled aria-busy="true"/);
});
test('Müşteri rolü veri yanlışlıkla gönderilse bile aktarım raporlarına erişim düğmesi görmez',()=>{
 const html=inventoryView({admin:false,query:'',filter:'all',data});
 assert.doesNotMatch(html,/data-inventory-import-dashboard|data-inventory-import-report|Mevcut bağlantıların aktarım durumu/);
 assert.equal(inventoryImportDashboardButton(data,false),'');
});
test('Bağlantılar müşteri kaynağından türetilir, aynı pazaryerinin geçmiş işi iki bağlantı sayılmaz',()=>{
 assert.deepEqual(inventoryImportCounts(data),{connections:2,active:1});
 assert.deepEqual(inventoryImportCounts({...data,connectionImports:[run('Başka mağaza')]}),{connections:1,active:0});
 assert.match(inventoryImportDashboardButton({},true),/Aktarım raporları/);
});
test('Metadata yalnız rapor modalında kalır ve legacy yanıtta da erişilebilir olur',()=>{
 const legacy={...data,connectionImports:[],metadata:{pending:3,error:'Kontrol bekliyor.'}};
 for(const admin of [true,false])assert.doesNotMatch(inventoryView({admin,query:'',filter:'all',data:legacy}),/Ürün bilgileri tamamlanıyor|stoklu ürünün adı|Ürün bilgisi aktarımı/);
 assert.match(inventoryImportDashboardButton(legacy,true),/data-inventory-import-dashboard/);
 const report=inventoryImportDashboardView(legacy);assert.match(report,/3 stoklu ürünün adı/);assert.match(report,/Kontrol bekliyor/);assert.doesNotMatch(report,/0 pazaryeri bağlantısı/);
 assert.match(inventoryImportDashboardView({...legacy,metadata:{pending:0}}),/Bekleyen ürün bilgisi bulunmuyor/);
});
test('Rapor listesi aynı güvenli sağlayıcı raporlarını kullanır, ayrıntı geri dönüş sunar',()=>{
 const list=inventoryImportDashboardView(data);
 assert.match(list,/data-inventory-import-report="snapshot-Trendyol"/);assert.match(list,/data-inventory-import-report="snapshot-n11"/);
 const detail=inventoryImportDashboardView(data,{selectedId:'snapshot-Trendyol'});
 assert.match(detail,/data-inventory-import-back/);assert.match(detail,/Aktarım aşamaları/);assert.doesNotMatch(detail,/data-inventory-import-report="snapshot-n11"/);
});
test('Bildirim ayrıntısının tarihsel sayımı korunurken güncel katalog özeti yenilenir',()=>{
 const summary={scope:'current_provider_catalog',provider:'Trendyol',providerLabel:'Trendyol',catalogRecords:145,products:140,saleActiveRecords:130,incompleteRecords:2};
 const current={...run('Trendyol'),kind:undefined,currentCatalogSummary:summary};
 const html=inventoryImportDashboardView({...data,connectionImports:[current]},{selectedId:current.runId,detail:{events:['marketplace_import_completed'],importReport:{...current,counts:{products:99},currentCatalogSummary:{...summary,catalogRecords:1}}}});
 assert.match(html,/>145</);assert.match(html,/>99</);assert.doesNotMatch(html,/>1<\/strong>/);
});
test('Hata ve sağlayıcı etiketleri güvenli metindir; başka müşterinin raporu seçilemez',()=>{
 const html=inventoryImportDashboardView({...data,connectionImports:[run('<script>')]},{selectedId:'unknown',error:'<img onerror=x>'});
 assert.doesNotMatch(html,/<script>|<img/);assert.match(html,/&lt;img/);assert.doesNotMatch(html,/data-inventory-import-back/);
});
