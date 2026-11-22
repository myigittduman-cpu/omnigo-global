import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryProgressView} from '../public/inventory-progress-view.js';
import {reorderNavigation} from '../public/navigation-reorder.js';

const data={settings:{mode:'both'},activation:{marketplaceActive:true,canStart:true},progress:{state:'running',percent:40,estimatedSeconds:180}};
test('Yüzde ve süre yalnız ilk ürün aktarımında görünür',()=>{
 const html=inventoryProgressView({...data,initialImport:{state:'running',complete:false,percent:50,estimatedSeconds:240,completedProviders:1,providerCount:2}},true);
 assert.match(html,/İlk ürün aktarımı sürüyor/);assert.match(html,/value="50"/);assert.match(html,/4 dakika/);
});
test('Tamamlanmış ilk aktarım, periyodik tarama veya hatada tekrar açılmaz',()=>{
 for(const state of ['running','retry','incomplete','complete']){
  const html=inventoryProgressView({...data,progress:{state,percent:20,estimatedSeconds:180},initialImport:{complete:true,state:'complete',percent:100}},true);
  assert.equal(html,'');
 }
});
test('Kalıcı hata ilk aktarım çubuğunu açmaz; yeniden deneme yalnız yöneticiye sunulur',()=>{
 const errorData={...data,persistentError:true,initialImport:{complete:true,state:'complete'}};
 const admin=inventoryProgressView(errorData,true),client=inventoryProgressView(errorData,false);
 assert.match(admin,/Stok bağlantısı kontrol gerektiriyor/);
 assert.match(admin,/data-inventory-start/);
 assert.doesNotMatch(admin,/<progress|Tahmini kalan süre/);
 assert.doesNotMatch(client,/data-inventory-start/);
});
test('Henüz başlatılmamış ve manuel müşteride dolum alanı bulunmaz',()=>{
 for(const change of [{settings:{mode:'store'}},{activation:{marketplaceActive:false} }]){
  assert.doesNotMatch(inventoryProgressView({...data,initialImport:{complete:false,percent:5},...change},true),/<progress/);
 }
});
test('Bilinmeyen ilerleme ve süre uydurulmaz',()=>{
 const html=inventoryProgressView({...data,initialImport:{complete:false,state:'running',percent:null,estimatedSeconds:null,providerCount:6}},false);
 assert.match(html,/İlerleme hesaplanıyor/);assert.doesNotMatch(html,/Kalan süre hesaplanıyor/);assert.doesNotMatch(html,/value="|dakika|data-inventory-start/);
});
test('Sürükleme aynı sekmeleri korur ve satır sınırından bağımsızdır',()=>{
 const ids=['overview','customers','orders','deliveries','shipments','returns','inventory'];
 assert.deepEqual(reorderNavigation(ids,'inventory','customers'),['overview','inventory','customers','orders','deliveries','shipments','returns']);
 assert.deepEqual(reorderNavigation(ids,'unknown','orders'),ids);assert.deepEqual(reorderNavigation(ids,'orders','orders'),ids);assert.equal(ids[6],'inventory');
});

test('Otomatik yeniden deneme ayrıntıları kapalı başlar ve kullanılamayan kontrol gizlenir',()=>{
 const html=inventoryProgressView({...data,activation:{marketplaceActive:true,canStart:false},persistentError:true,initialImport:{state:'retry'},progress:{providers:[{provider:'n11',state:'retry',error:'Geçici bağlantı sorunu'}]}},true);
 assert.match(html,/<details class="inventory-retry-line"><summary>/);
 assert.match(html,/n11/);assert.match(html,/Geçici bağlantı sorunu/);
 assert.doesNotMatch(html,/<progress|data-inventory-start|Kalan süre/);
});