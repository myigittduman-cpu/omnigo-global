import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryProgressView} from '../public/inventory-progress-view.js';
import {inventoryConnectionImportsView} from '../public/inventory-connection-imports.js';
import {inventoryImportDashboardView} from '../public/inventory-import-dashboard-view.js';
import {inventoryRefreshDelay,canRefreshInventory} from '../public/inventory-refresh-policy.js';
import {marketplaceImportReportView,marketplaceImportSummary,isMarketplaceImportNotification,marketplaceImportTitle} from '../public/marketplace-import-report.js';
import {operationNotificationDetail,operationNotificationTitle,operationNotificationLabel} from '../public/operation-notification.js';
import {notificationView} from '../public/notification-destination.js';

const run={runId:'run-1',provider:'trendyol',providerLabel:'Trendyol',state:'running',updatedAt:'2026-11-08T09:00:00Z',counts:{products:null,stockRecords:null,orders:7,returns:0},stages:{connection:{status:'complete'},orders:{status:'running',counts:{orders:7}},products:{status:'unsupported'},stock:{status:'unsupported'}}};
const data={settings:{mode:'both'},activation:{marketplaceActive:true,canStart:true},initialImport:{complete:true},connectionImports:[run]};
const existing={...run,kind:'live_snapshot',runId:'snapshot-hepsiburada-3',provider:'hepsiburada',providerLabel:'Hepsiburada',state:'complete',countsScope:'current_snapshot',startedAt:null,finishedAt:null,updatedAt:'2026-11-08T09:00:00Z'};

test('Eski bağlantının mevcut kayıtları yeni aktarım tamamlanmış gibi sunulmaz',()=>{
 for(const [state,label] of [['complete','Son aktarım kaydı mevcut'],['failed','Bağlantı kontrol gerektiriyor'],['unknown','Aktarım durumu doğrulanamadı']]){
  const value={...existing,state},viewData={...data,connectionImports:[value],metadata:{pending:3}},html=inventoryImportDashboardView(viewData);
  assert.match(html,/Mevcut bağlantıların aktarım durumu/);assert.match(html,new RegExp(label));
  assert.match(html,/data-inventory-import-report="snapshot-hepsiburada-3"/);assert.match(html,/Ürün bilgileri tamamlanıyor/);
  assert.doesNotMatch(html,/Aktarım tamamlandı|import-complete|<progress/);
  assert.equal(inventoryRefreshDelay({...data,connectionImports:[value]}),15000);
  assert.equal(inventoryProgressView({...data,connectionImports:[value]},false),'');
 }
});

test('Mevcut bağlantıda yalnız gerçek bekleyen iş hızlı izlenir; başarı yüzdesi üretilmez',()=>{
 for(const state of ['queued','running','retry']){
  const value={...existing,state},html=inventoryConnectionImportsView({...data,connectionImports:[value]},true);
  assert.match(html,/<progress aria-label="Pazaryeri aktarımı sürüyor">/);assert.doesNotMatch(html,/value="100"|%100/);
  assert.equal(inventoryRefreshDelay({...data,connectionImports:[value]}),3000);
 }
});

test('Mevcut bağlantı raporu kayıtlı toplamı ve yalnız gerçek kaynak tarihini açıklar',()=>{
 const html=marketplaceImportReportView({...existing,stages:{connection:{status:'complete'},orders:{status:'complete'},stock:{status:'not_enabled'},products:{status:'unsupported'}}});
 assert.match(html,/Son başarılı kayıt mevcut/);assert.match(html,/Etkin değil/);assert.match(html,/Desteklenmiyor/);
 assert.match(html,/şu anda kayıtlı toplamları gösterir; yeni aktarılan adet değildir/);
 assert.match(html,/datetime="2026-11-08T09:00:00.000Z"/);assert.doesNotMatch(html,/Aktarım tamamlandı|Bu özet, ilgili aktarım çalışmasının sonucunu/);
 const unknown=marketplaceImportReportView({...existing,state:'unknown',updatedAt:null,finishedAt:'2026-12-01T00:00:00Z',createdAt:'2026-12-01T00:00:00Z',counts:{products:null,orders:null,stockRecords:null,returns:null}});
 assert.doesNotMatch(unknown,/<time|<dt>Ürün|<dt>Sipariş/);assert.match(unknown,/Kayıt sayıları bu bağlantı için doğrulanamadı/);
});

test('Mevcut bağlantı özeti tamamlanma veya hata bildirimi olayı sayılmaz',()=>{
 for(const event of [existing,{events:['marketplace_import_completed'],importReport:existing},{...existing,events:['marketplace_import_failed']}]){
  assert.equal(isMarketplaceImportNotification(event),false);assert.equal(marketplaceImportTitle(event),'');
 }
});

test('Çalışma listesi olmayan eski yanıtta metadata görünümü ve normal yenileme korunur',()=>{
 for(const connectionImports of [undefined,null,[],{}]){
  const legacy={...data,connectionImports};
  assert.equal(inventoryProgressView(legacy,true),'');
  assert.equal(inventoryRefreshDelay(legacy),15000);
  const pending={...legacy,metadata:{pending:3,error:'Ürün adları yeniden kontrol edilecek.'}};
  for(const admin of [true,false]){
   const html=inventoryImportDashboardView(pending);
   assert.match(html,/Ürün bilgileri tamamlanıyor/);assert.match(html,/3 stoklu ürün/);
   assert.doesNotMatch(html,/inventory-connection-runs|data-inventory-import-report|Aktarım tamamlandı/);
  }
  assert.equal(inventoryRefreshDelay(pending),3000);
 }
});

test('Çalışma listesi yoksa ilk aktarım ve manuel stok panelleri yapay rapor üretmez',()=>{
 const legacy={...data,connectionImports:[]};
 const first=inventoryProgressView({...legacy,initialImport:{complete:false,state:'running',percent:null}},true);
 assert.match(first,/İlk ürün aktarımı sürüyor/);assert.match(first,/İlerleme hesaplanıyor/);
 const inactive=inventoryProgressView({...legacy,activation:{marketplaceActive:false,canStart:true}},true);
 assert.match(inactive,/Canlı aktarımı başlat/);
 const manual=inventoryProgressView({...legacy,settings:{mode:'store'}},true);
 assert.match(manual,/Mağaza stoğu · Manuel takip/);
 for(const html of [first,inactive,manual])assert.doesNotMatch(html,/data-inventory-import-report|Aktarım raporu/);
});

test('Yeni bağlantı çalışması tamamlanmış ilk aktarım tarafından gizlenmez; müşteri görünümü korunur',()=>{
 for(const state of ['queued','running','retry','complete','failed']){
  const html=inventoryConnectionImportsView({...data,connectionImports:[{...run,state}]},true);
  assert.match(html,/Pazaryeri aktarım durumu/);assert.match(html,/data-inventory-import-report="run-1"/);
  assert.equal((html.match(/inventory-connection-progress/g)||[]).length,1);
  assert.equal((html.match(/inventory-import-counts/g)||[]).length,1);
  assert.equal((html.match(/inventory-import-run-note/g)||[]).length,1);
 }
 assert.equal(inventoryProgressView(data,false),'');
 const metadata=inventoryImportDashboardView({...data,metadata:{pending:3}});
 assert.match(metadata,/Ürün bilgileri tamamlanıyor/);assert.match(metadata,/Pazaryeri aktarım durumu/);
});

test('Yeni çalışma ilk etkinleştirme düğmesini ve manuel takip görünümünü değiştirmez',()=>{
 const pending=inventoryProgressView({...data,activation:{marketplaceActive:false,canStart:true},initialImport:{complete:false}},true);
 assert.match(pending,/data-inventory-start/);assert.match(pending,/Canlı aktarımı başlat/);
 const manual=inventoryProgressView({...data,settings:{mode:'store'}},true);
 assert.match(manual,/Mağaza stoğu · Manuel takip/);assert.match(inventoryConnectionImportsView(data,true),/Aktarım ayrıntıları/);
 assert.doesNotMatch(manual,/data-inventory-start/);
});

test('Bağlantı işlemi sürerken hızlı izleme ve mevcut taslak korumaları devam eder',()=>{
 for(const state of ['queued','running','retry'])assert.equal(inventoryRefreshDelay({...data,connectionImports:[{...run,state}]}),3000);
 for(const state of ['complete','failed'])assert.equal(inventoryRefreshDelay({...data,connectionImports:[{...run,state}]}),15000);
 const model={busy:false,selected:new Set(),query:''};
 assert.equal(canRefreshInventory(model,{dialogOpen:true}),false);
 assert.equal(canRefreshInventory(model,{focusedTag:'INPUT'}),false);
 assert.equal(canRefreshInventory(model,{draftQuery:'Yazılmakta'}),false);
});

test('Rapor yalnız bilinen sayıları ve kaynakça doğrulanmış aşamaları gösterir',()=>{
 assert.equal(marketplaceImportSummary(run),'7 sipariş · 0 iade talebi');
 const html=marketplaceImportReportView({importReport:run});
 assert.match(html,/Sipariş/);assert.match(html,/>7</);assert.match(html,/Desteklenmiyor/);
 assert.doesNotMatch(html,/<dt>Ürün<\/dt>|<dt>Stok kaydı<\/dt>|NaN|undefined|%100/);
 const manual=marketplaceImportReportView({importReport:{...run,stages:{stock:{status:'not_enabled'}}}});
 assert.match(manual,/Etkin değil/);assert.doesNotMatch(manual,/Stok bilgileri<\/th><td>Aktarım tamamlandı/);
 const snapshot=marketplaceImportReportView({importReport:{...run,state:'complete',countsScope:'persisted_snapshot',warnings:['Bazı ürün bilgileri tamamlanıyor.','<img onerror=bad>']}});
 assert.match(snapshot,/Sayılar, aktarım sonunda bu pazaryeri için kayıtlı toplamları gösterir/);
 assert.match(snapshot,/Bazı ürün bilgileri tamamlanıyor/);assert.match(snapshot,/&lt;img/);assert.doesNotMatch(snapshot,/<img/);
});

test('Başarı ve hata bildirimleri sipariş yerine aktarım raporunu açar; rapor güvenli metin kullanır',()=>{
 for(const [eventName,state,title] of [['marketplace_import_completed','complete','Pazaryeri aktarımı tamamlandı'],['marketplace_import_failed','failed','Pazaryeri aktarımı tamamlanamadı']]){
  const event={events:[eventName],importReport:{...run,state,providerLabel:'<img onerror=bad>',error:state==='failed'?'<script>bad</script>':null},buyerName:'GİZLİ MÜŞTERİ',apiKey:'GİZLİ ANAHTAR'};
  assert.equal(operationNotificationTitle(event),title);assert.equal(operationNotificationLabel(event),title);
  const html=operationNotificationDetail(event);assert.match(html,/Aktarım aşamaları/);
  assert.doesNotMatch(html,/<img|<script|GİZLİ MÜŞTERİ|GİZLİ ANAHTAR/);assert.match(html,/&lt;img/);
  if(state==='failed')assert.match(html,/role="alert"/);
  assert.equal(notificationView({events:[eventName],type:'operation'},'admin'),'inventory');
 }
});
