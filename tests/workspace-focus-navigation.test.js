import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceFocusGroups,workspaceFocusCategory,workspaceFocusModel} from '../public/workspace-focus-model.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';

const names={overview:'Genel bakış',customers:'Müşteriler',channels:'Pazaryerlerim',orders:'Siparişler',inventory:'Stok Yönetimi',shipments:'Şehir Dışı Sevkiyat',deliveries:'Şehir Dışı Teslimatlar',returns:'İade Talepleri',analytics:'Analiz ve raporlar',payments:'Hakedişlerim',tasks:'Çalışma takibi',reports:'Raporlarım',support:'Destek talepleri',connections:'Bağlantı kontrolü'};
const pageIds=html=>[...html.matchAll(/data-nav="([^"]+)"/g)].map(match=>match[1]);

test('Odak grupları finans, operasyon ve talep menülerini yerleştirir',()=>{
 assert.equal(workspaceFocusCategory('inventory'),'operations');
 assert.equal(workspaceFocusCategory('payments'),'finance');
 assert.equal(workspaceFocusCategory('tasks'),'management');
 assert.equal(workspaceFocusCategory('returns'),'operations');
 assert.equal(workspaceFocusCategory('future-page'),'management');
 assert.deepEqual(workspaceFocusModel([]),{groups:[],selected:''});
 assert.deepEqual(workspaceFocusModel(['inventory'],{view:'inventory'}).groups.map(group=>group.id),['operations']);
});

test('Aynı sayfa yenilendiğinde gezilen kategori korunur; kaldırılmış kategori seçilemez',()=>{
 assert.equal(workspaceFocusModel(['overview','inventory','payments'],{view:'inventory',selectedCategory:'finance'}).selected,'finance');
 assert.equal(workspaceFocusModel(['overview','inventory'],{view:'inventory',selectedCategory:'finance'}).selected,'operations');
 assert.equal(workspaceFocusModel(['support'],{view:'inventory'}).selected,'system');
});

test('Faturalar ana menüden kalkar, şehir dışı ürünler operasyon altında görünür',()=>{
 const documentNames={shipmentInvoices:'Faturalar',deliveries:'Şehir Dışı Teslimatlar',cityProducts:'Şehir Dışı Ürünler'};
 const data={modules:{shipments:true,deliveries:true},workspaceAccess:{pages:{shipments:'shown',deliveries:'shown',shipmentInvoices:'shown',cityProducts:'shown'},grants:{shipments:{view:true,documents:true},deliveries:{view:true},shipmentInvoices:{view:true},cityProducts:{view:true}}}};
 for(const role of ['admin','client']){
  const view='shipmentInvoices',html=workspaceNavigation(documentNames,{user:{role},view,data});
  assert.doesNotMatch(html,/data-menu-group="finance"|data-nav="shipmentInvoices"/);
  assert.deepEqual(pageIds(html),['deliveries','cityProducts']);
  assert.equal(workspaceFocusCategory('cityProducts',role),'operations');
  assert.equal(pageIds(html).indexOf('cityProducts'),pageIds(html).indexOf('deliveries')+1);
  assert.doesNotMatch(workspaceNavigation({...documentNames,shipmentDeliveryDocuments:'Teslimat Evrakları'},{user:{role},view,data}),/data-nav="shipmentDeliveryDocuments"/);
 }
});

test('Tüm görünürlük kombinasyonlarında yalnız dolu kategoriler oluşur ve seçim hep geçerlidir',()=>{
 const pages=Object.keys(names);
 for(let mask=0;mask<2**pages.length;mask++){
  const visible=pages.filter((_,index)=>Boolean(mask&(2**index)));
  const result=workspaceFocusModel(visible,{view:'inventory',selectedCategory:'finance'});
  const expected=workspaceFocusGroups.filter(group=>visible.some(page=>workspaceFocusCategory(page)===group.id)).map(group=>group.id);
  assert.deepEqual(result.groups.map(group=>group.id),expected);
  assert.equal(new Set(result.groups.map(group=>group.id)).size,result.groups.length);
  assert(visible.length?expected.includes(result.selected):result.selected==='');
 }
});

test('Sekmeler müşteri yetki filtresinin vermediği hiçbir düğmeyi üretmez',()=>{
 const state={
  user:{id:2,role:'client'},view:'inventory',
  data:{
   modules:{shipments:true,deliveries:true},
   workspaceAccess:{
    pages:{overview:'shown',orders:'shown',inventory:'shown',shipments:'hidden',deliveries:'removed',analytics:'hidden',payments:'shown',tasks:'hidden',reports:'hidden',support:'shown',channels:'hidden',returns:'hidden'},
    grants:{overview:{view:true},orders:{view:false},inventory:{view:true},payments:{view:true},support:{view:true}}
   }
  }
 };
 assert.deepEqual(pageIds(workspaceNavigation(names,state)),['overview','inventory','payments','support']);
 assert.doesNotMatch(workspaceNavigation(names,state),/data-hidden-menus|data-nav="(?:orders|customers|connections|returns|shipments|deliveries)"/);
 assert.deepEqual(workspaceFocusModel(pageIds(workspaceNavigation(names,state)),{view:state.view}).groups.map(group=>group.id),['management','operations','finance','system']);
});

test('Bekleyen talep sayacı yönetici menüsünde gösterilir',()=>{
 const state={user:{id:1,role:'admin'},view:'returns',data:{workspaceAccess:{pages:{tasks:'hidden',reports:'hidden'}},marketplaceRequestsPending:2}};
 const html=workspaceNavigation(names,state);
 assert(!pageIds(html).includes('tasks'));assert(!pageIds(html).includes('reports'));
 assert.doesNotMatch(html,/data-hidden-menus|Gizlenen menüler/);
 assert.match(html,/data-nav="returns"[^>]+data-request-pending="true"/);
 assert.match(html,/data-request-count[^>]+>2</);
});

test('Yanıt bekleyen soru toplamı yetkili menüde altın sayaç alanına yazılır',()=>{
 const names={inbox:'Pazaryeri Soruları'};
 const state={user:{id:1,role:'admin'},view:'inbox',data:{marketplaceQuestionsPending:17}};
 const menu=workspaceNavigation(names,state);
 assert.match(menu,/data-nav="inbox"[^>]*>[\s\S]*data-question-count[^>]*aria-label="17 yanıt bekleyen soru">17</);
 assert.doesNotMatch(menu,/data-request-pending/);
 state.data.marketplaceQuestionsPending=0;
 assert.match(workspaceNavigation(names,state),/data-question-count hidden[^>]*><\/span>/);
 assert.doesNotMatch(workspaceNavigation(names,{...state,user:{role:'client'}}),/data-question-count|data-nav="inbox"/);
});
