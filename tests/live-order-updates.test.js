import test from 'node:test';
import assert from 'node:assert/strict';
import {bindDelivery} from '../public/delivery.js';
import {bindOrders} from '../public/orders.js';
import {now} from '../public/ui.js';
import {monthDateRange} from '../public/filter-date-range.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';

const order=(id,extra={})=>({id,version:1,orderNumber:`TEST-${id}`,city:'Ankara',provider:'n11',active:true,issues:[],products:[],...extra});
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function environment(fetch){
 const values={fetch,document:{activeElement:null,querySelector:()=>null,addEventListener(){}},
  window:{addEventListener(){},dispatchEvent(){}},MutationObserver:class{observe(){}disconnect(){}},setTimeout:()=>0,clearTimeout(){}};
 const saved=Object.fromEntries(Object.keys(values).map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 for(const [key,value] of Object.entries(values))Object.defineProperty(globalThis,key,{value,configurable:true});
 return ()=>{for(const [key,value] of Object.entries(saved))if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];};
}
test('Evrak seçimi: yeni, iptal, eksik ve kısmi siparişler güvenli yenilenir',()=>{
 const model={queue:'all',manualSelection:true,selected:new Set([1,2,3,4])};
 const items=[order(1,{version:2,products:[{quantity:1}]}),order(2,{active:false}),order(3,{issues:['Telefon eksik']}),order(5)];
 assert.deepEqual([...refreshedDeliverySelection(model,items)],[1]);
 assert.deepEqual([...model.selected],[1,2,3,4]);
 assert.deepEqual([...refreshedDeliverySelection({...model,manualSelection:false},items)],[1,5]);
 assert.deepEqual([...refreshedDeliverySelection({...model,selected:new Set()},items)],[]);
 assert.deepEqual([...refreshedDeliverySelection({...model,queue:'delayed'},items)],[]);
});
test('Teslimat ekranı: elle seçimden sonra veri yenilenir; iptal seçimi çıkar, yeni sipariş seçime eklenmez',async()=>{
 let items=[order(1),order(2)],calls=0,editing=false;const handlers={};
 const root={isConnected:true,innerHTML:'',addEventListener:(event,handler)=>handlers[event]=handler,querySelector:()=>editing?{}:null,querySelectorAll:()=>[]};
 const state={user:{id:1,role:'admin'},company:1,view:'deliveries'};
 const restore=environment(async()=>{calls++;return {ok:true,json:async()=>({items,cities:[],sync:{}})};});
 try{
  bindDelivery({querySelector:()=>root},state);await flush();assert.match(root.innerHTML,/Toplam: 2 sipariş/);
  await handlers.change({target:{matches:s=>s==='[data-order]',dataset:{order:'2'},checked:false}});
  items=[order(1,{active:false}),order(2),order(3)];await root.deliveryRefresh();
  assert.equal(calls,2);assert.match(root.innerHTML,/TEST-3/);assert.doesNotMatch(root.innerHTML,/TEST-1/);assert.match(root.innerHTML,/Toplam: 0 sipariş/);
  editing=true;await root.deliveryRefresh();assert.equal(calls,2);editing=false;
  state.company=2;await root.deliveryRefresh();assert.equal(calls,2);
 }finally{restore();}
});
test('Müşterinin Siparişler ekranı: kapatılan detay düğmesinin odağı yenilemeyi durdurmaz; tarih taslağı ve açık detay korunur',async()=>{
 let calls=0,dialog=false;const day=now();
 const range=monthDateRange(day);
 const form={elements:{provider:{value:'all'},query:{value:''},from:{value:range.from},to:{value:range.to}}};
 const root={isConnected:true,innerHTML:'',addEventListener(){},querySelector:selector=>selector==='form'?form:null,contains:el=>Boolean(el)};
 const restore=environment(async()=>{calls++;return {ok:true,json:async()=>({items:[],page:0,total:0,sync:[]})};});
 try{
  document.querySelector=()=>dialog?{}:null;
  form.elements.namedItem=name=>form.elements[name];
  Object.assign(form.elements.query,{form,setAttribute(){},removeAttribute(){}});
  root.ownerDocument=document;
  const state={user:{id:1,role:'client'},company:1,view:'orders',data:{workspaceAccess:{pages:{orders:'shown'},grants:{orders:{view:true}}}}};
  bindOrders({querySelector:()=>root},state);await flush();
  document.activeElement={matches:()=>false};await root.ordersRefresh();assert.equal(calls,2);
  document.activeElement={matches:()=>true};await root.ordersRefresh();assert.equal(calls,2);
  document.activeElement=null;form.elements.provider.value='n11';await root.ordersRefresh();assert.equal(calls,2);
  form.elements.provider.value='all';dialog=true;await root.ordersRefresh();assert.equal(calls,2);
  dialog=false;await root.ordersRefresh();assert.equal(calls,3);
 }finally{restore();}
});

test('Müşteri teslimat listesi salt okunur ve canlıdır; listeye eklenen sipariş kalır',async()=>{
 let items=[order(1,{dispatchState:'pending'})];const calls=[],handlers={};
 const root={isConnected:true,innerHTML:'',addEventListener:(event,handler)=>handlers[event]=handler,querySelector:()=>null,querySelectorAll:()=>[]};
 const state={user:{id:2,role:'client'},company:1,view:'deliveries'};
 const restore=environment(async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>({items,cities:[],sync:{}})};});
 try{
  bindDelivery({querySelector:()=>root},state);await flush();
  assert.match(calls[0].url,/\/api\/delivery\/orders\?.*mode=all.*company=1/);
  assert.doesNotMatch(root.innerHTML,/data-order=|data-create|data-excel|data-correct-order|data-delivery-share|data-delivery-additions/);
  items=[order(1,{deliveryListAdded:true,deliveryStatus:'Sevk bekliyor'}),order(2,{issues:['Telefon eksik']})];
  await root.deliveryRefresh();assert.match(root.innerHTML,/TEST-1/);assert.match(root.innerHTML,/Sevk bekliyor/);
  assert.doesNotMatch(root.innerHTML,/data-correct-order/);
  for(const action of ['data-create','data-excel','data-correct-order','data-delivery-share']){
   await handlers.click({target:{closest:()=>({matches:selector=>selector.includes('['+action+']')})}});
  }
  assert.equal(calls.length,2);
  items=[order(1,{active:false,dispatchState:'delivered'}),order(2,{active:false,status:'İptal edildi'})];
  await root.deliveryRefresh();assert.doesNotMatch(root.innerHTML,/TEST-1|TEST-2/);
  state.company=2;await root.deliveryRefresh();assert.equal(calls.length,3);
  assert.ok(calls.every(call=>!call.options?.method||call.options.method==='GET'));
 }finally{restore();}
});
