import test from 'node:test';
import assert from 'node:assert/strict';
import {canUseMarketplaceStockPilot} from '../public/marketplace-stock-pilot.js';
import {customerCan,customerPageVisible} from '../public/customer-access.js';
import {adminCanOpenHiddenPage} from '../public/admin-hidden-menus.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {stockListingPages,stockListingRows,stockProviderOptions} from '../public/marketplace-stock-list-view.js';
import {listingTransferLines} from '../public/marketplace-listing-edits.js';
import {stockSettingsReports} from '../public/marketplace-stock-settings.js';
import {stockChangeBody,stockRecheckState} from '../public/marketplace-stock-list-action.js';

test('Mağaza bağlantı özetinde kaynak güncelliği ve işlem desteği ayrı gösterilir',()=>{
 const row=(provider,updatedAt,sourceState='verified')=>({provider,providerLabel:provider,updatedAt,stages:{connection:{status:'complete'}},currentCatalogSummary:{sourceState}});
 const rows=stockSettingsReports([row('n11','2026-11-20'),row('n11','2026-11-21','unverified'),row('pttavm','2026-11-21'),row('pazarama','2026-11-21')]);
 assert.equal((rows.match(/scope="row"/g)||[]).length,3);assert.match(rows,/Güncellik doğrulanamadı/);
 for(const label of ['Uygun ilanlarda stok gönderimi','Yalnız görüntüleme','Aktif satış durumu doğrulanamıyor'])assert.match(rows,new RegExp(label));
 assert.match(stockSettingsReports([]),/raporu bulunamadı/);
});

test('Eski Hepsiburada işi okunur, belirsiz stok için yalnız pilot işlerinde tekrar kontrol görünür',()=>{
 assert.deepEqual(stockRecheckState({requestId:'pilot:hepsiburada:123',state:'uncertain'}),{available:true,note:''});
 assert.equal(stockRecheckState({requestId:'pilot:n11:123',state:'queued'}).available,false);
 assert.equal(stockRecheckState({requestId:'old-id',state:'succeeded'}).note,'');
 const legacy=stockRecheckState({requestId:'4df3cd7c-e13f-43d9-8182-69253e49e2d8',state:'uncertain'});
 assert.equal(legacy.available,false);assert.match(legacy.note,/Stok Yönetimi > Hareketler/);
});

const companies=[{id:1,name:'Diğer'},{id:2,name:'Pilot',marketplaceStockPilot:true}];
const state=(company,role)=>({company,user:{id:5,role},companies,view:'marketplaceStocks'});
const names={inventory:'Stok Yönetimi',marketplaceStocks:'Pazaryeri Stokları',shipments:'Şehir Dışı Sevkiyatlar'};
for(const [company,role,expected] of [[1,'admin',false],[2,'admin',true],[2,'client',false],[1,'client',false],[0,'admin',false]]){
 test(role+' için mağaza '+company+' pilot menü ve doğrudan gezinme',()=>{
  const scope=state(company,role);
  assert.equal(canUseMarketplaceStockPilot(scope),expected);
  assert.equal(customerPageVisible(scope,'marketplaceStocks'),expected);
  assert.equal(adminCanOpenHiddenPage(scope,'marketplaceStocks'),expected);
  assert.equal(customerCan(scope,'marketplaceStocks','export'),false);
  const html=workspaceNavigation(names,scope);
  assert.equal(html.includes('data-nav="marketplaceStocks"'),expected);
  if(expected)assert.deepEqual([...html.matchAll(/data-nav="([^"]+)"/g)].map(m=>m[1]),['inventory','marketplaceStocks','shipments']);
 });
}
test('Sunucu tarafından verilmemiş veya metin olarak yazılmış bayrak menüyü açmaz',()=>{
 const scope=state(2,'admin');scope.companies=[{id:2,marketplaceStockPilot:'true'}];
 assert.equal(canUseMarketplaceStockPilot(scope),false);
});
test('Stok miktarı bilinmeyenle sıfırı ayırır ve yalnız doğrulanmış hedef için işlem gösterir',()=>{
 const product={key:'s',sku:'X & Y',title:'<etiket>',provider:'n11',observedAt:'2026-11-21T06:15:00Z'};
 assert.match(stockListingRows([{...product,quantity:0,writeTarget:{key:'s'}}]),/data-quantity[^>]*value="0"/);
 const unknown=stockListingRows([{...product,quantity:null}]);
 assert.match(unknown,/>—<\/td>/);assert.match(unknown,/&lt;etiket&gt;/);assert.doesNotMatch(unknown,/data-stock-list-action/);
 assert.match(stockListingRows([{...product,quantity:1,writeTarget:{requestId:'pilot:n11:a'}}]),/data-save-listing/);
});
test('Filtre seçimi ve numaralı 15’li sayfalar korunur',()=>{
 assert.match(stockProviderOptions(['Trendyol','n11'],'n11'),/value="n11" selected/);
 assert.equal(stockListingPages({page:0,total:15,pageSize:15}),'');
 assert.match(stockListingPages({page:1,total:16,pageSize:15}),/data-stock-list-page="1"[^>]+aria-current="page"/);
});
test('Dört pazaryeri tek stok kartında ve sonuç oku işlemle aynı hücrede',()=>{
 const row=stockListingRows([{key:'card',cardKey:'card',providers:['Hepsiburada','n11','PTT AVM','Trendyol'],
  title:'Ortak ürün',sku:'ABC',writeTarget:{key:'offer'},mixedQuantity:true,mixedPrice:true}]);
 assert.equal((row.match(/<tr /g)||[]).length,1);
 assert.equal((row.match(/role="listitem"/g)||[]).length,4);
 assert.match(row,/class="listing-actions">\s*<button[^>]+data-save-listing[^<]+<\/button>\s*<button[^>]+data-transfer-report/);
 assert.match(row,/data-quantity[^>]*value=""/);
 assert.match(row,/data-price[^>]*placeholder=""[^>]*value=""/);
 assert.doesNotMatch(row,/Farklı/);
 const single=stockListingRows([{key:'card',providers:['Hepsiburada'],title:'Tek ilan',sku:'ABC',quantity:1,writeTarget:{key:'offer'}}]);
 assert.match(single,/role="listitem" title="Hepsiburada" aria-label="Hepsiburada"/);
 assert.match(single,/data-quantity[^>]*value="1"/);
 assert.doesNotMatch(single,/>Hepsiburada</);
 assert.doesNotMatch(row,/data-results/);
 const result=listingTransferLines([{provider:'Hepsiburada',state:'succeeded'},{provider:'n11',state:'missing'}]);
 assert.equal((result.match(/class="listing-transfer-line"/g)||[]).length,7);
 assert.match(result,/data-state="succeeded"[\s\S]+?listing-success/);
 assert.match(result,/data-state="missing"[\s\S]+?listing-failure/);
});
test('Tek hedefin tutarlı istek kimliği, sıfır dahil geçerli stok ve kaynak miktarı',()=>{
 const target={key:'a',productKey:'b',provider:'Trendyol',quantity:8};
 const req=stockChangeBody(target,'0','request-123');
 assert.deepEqual(req,{requestId:'request-123',productKey:'b',listingKey:'a',provider:'Trendyol',sourceQuantity:8,quantity:0,confirmed:true});
 assert.deepEqual(stockChangeBody(target,'0','request-123'),req);
 assert.equal(stockChangeBody({...target,provider:'Hepsiburada',identityToken:'abc'},'2','hb').identityToken,'abc');
 for(const raw of ['','  ','x','-1','1.5','1e2','8','100000001'])assert.equal(stockChangeBody(target,raw,'id'),null);
 assert.equal(stockChangeBody({...target,quantity:null},'1','id'),null);
});
