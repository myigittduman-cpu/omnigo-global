import test from 'node:test';import assert from 'node:assert/strict';
import {rememberUpdate} from '../public/web-update-state.js';
import {UPDATE_RETURN_KEY,rememberUpdateReturn,pendingUpdateReturn,takeUpdateReturn} from '../public/update-return-state.js';
import {pageSnapshot,clearPageSnapshots,capturePagePosition,stagePagePosition} from '../public/workspace-page-cache.js';
const storage=()=>{const values=new Map();return {getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const state=()=>({user:{id:7,role:'admin',password:'saklanmaz'},company:2,view:'inventory',month:'2026-11',filter:'all',data:{orders:['işletme verisi']},dateRange:{company:2,from:'2026-11-01',to:'2026-11-30',automatic:false}});
test('Güncelleme dönüşü yalnız konumu saklar; form, şifre ve işletme kayıtları saklanmaz',()=>{
 const store=storage(),s=state();rememberUpdate(store,'0.9.275',1000);rememberUpdateReturn(store,s,{position:{page:3,sort:'title',query:'Ürün',data:{secret:'yok'},password:'yok'}},1000);
 const row=pendingUpdateReturn(s.user,store,1500);assert.equal(row.view,'inventory');assert.deepEqual(row.position,{page:3,sort:'title',query:'Ürün'});assert.deepEqual(row.dateRange,s.dateRange);
 const text=store.getItem(UPDATE_RETURN_KEY);for(const forbidden of ['saklanmaz','işletme verisi','password','secret'])assert(!text.includes(forbidden));
 assert.equal(takeUpdateReturn(s.user,[{id:2}],store,1500).companyId,2);assert.equal(takeUpdateReturn(s.user,[{id:8}],store,1500),null);
});
test('Farklı hesap, rol, müşteri, eski kayıt veya normal açılış otomatik dönüş yapamaz',()=>{
 const store=storage(),s=state();rememberUpdate(store,'0.9.275',1000);rememberUpdateReturn(store,s,{},1000);
 for(const user of [{id:8,role:'admin'},{id:7,role:'client',company_id:2},{id:7,role:'client',company_id:8}])assert.equal(pendingUpdateReturn(user,store,1500),null);
 assert.equal(pendingUpdateReturn(s.user,store,901000),null);store.removeItem('omnigo.web-update.v1');assert.equal(pendingUpdateReturn(s.user,store,1500),null);
});
test('Müşteri yalnız kendi mağazasında döner; kayıtlı şirket artık yoksa dönüş yapılmaz',()=>{
 const store=storage(),s={...state(),user:{id:9,role:'client',company_id:2}};rememberUpdate(store,'0.9.275',1000);rememberUpdateReturn(store,s,{},1000);
 assert(pendingUpdateReturn(s.user,store,1500));assert.equal(pendingUpdateReturn({...s.user,company_id:3},store,1500),null);
});
test('Güncelleme sonrası yeni yönetici sayfaları aynı mağazada açılır',()=>{
 const store=storage(),s=state();rememberUpdate(store,'0.9.411',1000);
 for(const view of ['inbox','financeProfitability','returns']){
  rememberUpdateReturn(store,{...s,view},{position:{page:2}},1000);
  const row=takeUpdateReturn(s.user,[{id:2}],store,1500);
  assert.equal(row.view,view);
  assert.deepEqual(row.position,{page:2});
 }
});
test('Eski mağaza içi giriş kaydı ve kaldırılan sayfa güvenli çalışma ekranına döner',()=>{
 const store=storage(),s=state();rememberUpdate(store,'0.9.411',1000);
 rememberUpdateReturn(store,s,{position:{page:2},scroll:{x:7,y:280,app:120,tables:[]}},1000);
 const record=JSON.parse(store.getItem(UPDATE_RETURN_KEY));
 for(const view of ['entry','financeRefunds','removedPage']){
  store.setItem(UPDATE_RETURN_KEY,JSON.stringify({...record,view}));
  const row=takeUpdateReturn(s.user,[{id:2}],store,1500);
  assert.equal(row.view,'overview');assert.equal(row.companyId,2);assert.deepEqual(row.dateRange,s.dateRange);
  assert.equal(row.position,null);assert.deepEqual(row.scroll,{x:0,y:0,app:0,tables:[]});
 }
 rememberUpdateReturn(store,{...s,company:null},undefined,1000);
 assert.equal(takeUpdateReturn(s.user,[{id:2}],store,1500).view,'entry');
});
test('Müşteri güncellemeden yönetici sayfasına dönemez',()=>{
 const store=storage(),s={...state(),user:{id:9,role:'client',company_id:2}};
 rememberUpdate(store,'0.9.411',1000);
 rememberUpdateReturn(store,s,undefined,1000);
 const record=JSON.parse(store.getItem(UPDATE_RETURN_KEY));
 for(const view of ['inbox','financeProfitability','financeRefunds','entry']){
  store.setItem(UPDATE_RETURN_KEY,JSON.stringify({...record,view}));
  assert.equal(takeUpdateReturn(s.user,[{id:2}],store,1500).view,'channels');
 }
 rememberUpdateReturn(store,{...s,view:'inbox'},undefined,1000);
 assert.equal(JSON.parse(store.getItem(UPDATE_RETURN_KEY)).view,'channels');
});
test('İptal ve iade talepleri için yalnızca çalışma konumu hatırlanır',()=>{
 const store=storage(),s={...state(),user:{id:9,role:'client',company_id:2},view:'returns'};
 rememberUpdate(store,'0.9.411',1000);
 rememberUpdateReturn(store,s,{position:{page:2,items:['gizli kayıt']}},1000);
 const returned=takeUpdateReturn(s.user,[{id:2}],store,1500);
 assert.equal(returned.view,'returns');
 assert.deepEqual(returned.position,{page:2});
});
test('Tablo sayfası ve filtre geri gelir; veri, seçim ve sürüm başka müşteriye taşınmaz',()=>{
 clearPageSnapshots();const s=state(),model={data:{items:[{key:'ürün'}]},page:2,pageSize:50,sort:'sku',query:'A',selected:['ürün'],revision:8};
 pageSnapshot(s,'inventory',Object.keys(model)).save(model);const position=capturePagePosition(s);assert.deepEqual(position,{page:2,pageSize:50,sort:'sku',query:'A'});
 clearPageSnapshots();stagePagePosition(s,position);const restored={};assert.equal(pageSnapshot({...s,company:3},'inventory',[]).restore({}),false);assert.equal(pageSnapshot(s,'inventory',[]).restore(restored),true);assert.deepEqual(restored,position);assert.equal(pageSnapshot(s,'inventory',[]).restore({}),false);
});
