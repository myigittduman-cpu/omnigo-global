import test from 'node:test';
import assert from 'node:assert/strict';
import {clearWorkspace,selectWorkspace,selectCustomerWorkspace,hasWorkspace,workspaceToken,sameWorkspace} from '../public/workspace-context.js';
import {customerSelector,bindCustomerSelector} from '../public/customer-selector.js';
import {customerContext} from '../public/customer-page.js';

test('Müşteri seçimi verileri temizler; eski istekler aynı müşteriye dönüldüğünde bile uygulanamaz',()=>{
 const state={user:{id:1},companies:[{id:1,name:'Bir'},{id:2,name:'İki'}]};
 clearWorkspace(state);assert.equal(hasWorkspace(state),false);
 selectWorkspace(state,1);assert.equal(hasWorkspace(state),false);
 state.data={sales:[1]};state.inboxData={items:[1]};state.supportData={items:[1]};state.inboxUnreadOnly=true;
 const token=workspaceToken(state);assert.equal(hasWorkspace(state),true);
 selectWorkspace(state,2);assert.equal(state.data,null);assert.equal(state.inboxData,null);assert.equal(state.supportData,null);assert.equal(state.inboxUnreadOnly,false);
 assert.equal(sameWorkspace(state,token),false);
 selectWorkspace(state,1);assert.equal(sameWorkspace(state,token),false);
 assert.throws(()=>selectWorkspace(state,999),/Müşteri bulunamadı/);
 clearWorkspace(state);assert.equal(state.company,null);assert.equal(state.view,'overview');
});

test('Müşteriler sayfasında seçilen müşteri diğer sayfalarda korunur; önceki veriler temizlenir',()=>{
 const state={user:{id:1,role:'admin'},companies:[{id:1,name:'Bir'},{id:2,name:'İkinci <Müşteri>'},{id:3,name:'Üçüncü'}]};
 selectWorkspace(state,1);state.view='customers';state.shipmentSelection={company:1,ids:[9]};state.data={orders:[9]};
 state.reportFilters={marketplace:'old'};state.notificationData={items:[9]};
 const first=workspaceToken(state);
 selectCustomerWorkspace(state,2);assert.equal(state.view,'customers');assert.equal(state.company,2);
 assert.equal(state.data,null);assert.equal(state.shipmentSelection,null);assert.equal(state.reportFilters,null);assert.equal(state.notificationData,null);assert.equal(sameWorkspace(state,first),false);
 assert.match(customerSelector(state),/value="2" selected/);assert.match(customerSelector(state),/data-workspace-company/);assert.doesNotMatch(customerSelector(state),/<Müşteri>/);
 const second=workspaceToken(state);
 for(const view of ['deliveries','shipments','orders','analytics','reports','customers']){state.view=view;assert.equal(state.company,2);assert.equal(sameWorkspace(state,second),true);}
 selectCustomerWorkspace(state,3);assert.equal(state.company,3);assert.equal(sameWorkspace(state,second),false);
 selectCustomerWorkspace(state,1);assert.equal(sameWorkspace(state,first),false);
 const current=workspaceToken(state);assert.throws(()=>selectCustomerWorkspace(state,999),/Müşteri bulunamadı/);assert.equal(sameWorkspace(state,current),true);
 clearWorkspace(state);assert.equal(state.company,null);
});

test('Müşteri seçicisi yalnız Müşteriler sayfasında görünür; diğer sayfalarda kimlik korunur',()=>{
 const company={id:1,name:'Bir',hasLogo:true,brandingVersion:1};
 const state={user:{role:'admin'},company:1,companies:[company,{id:2,name:'İki'}],data:{channels:[]}};
 for(const view of ['overview','customers','channels','orders','shipments','deliveries','analytics','connections','payments','tasks','reports','support','returns']){
  state.view=view;const html=customerContext(state,company);assert.match(html,/data-company-logo="1"/);
  if(view==='customers')assert.match(html,/data-workspace-company/);else assert.doesNotMatch(html,/data-workspace-company|İki/);
 }
 state.view='customers';state.user.role='client';assert.equal(customerSelector(state),'');assert.doesNotMatch(customerContext(state,company),/data-workspace-company|İki/);assert.throws(()=>selectCustomerWorkspace(state,2),/yetkiniz yok/);
});

test('Kayıt koruması müşteri değişimini iptal ederse seçici önceki müşteriye döner',async()=>{
 const state={user:{role:'admin'},view:'customers',company:1,companies:[{id:1},{id:2}]};
 let change,resolveChange;
 const select={value:'2',disabled:false,isConnected:true,addEventListener(type,handler){assert.equal(type,'change');change=handler;}};
 const root={querySelectorAll:()=>[select]};
 bindCustomerSelector(root,state,()=>new Promise(resolve=>{resolveChange=resolve;}));
 const pending=change();assert.equal(select.disabled,true);assert.equal(state.company,1);
 resolveChange(false);await pending;assert.equal(select.value,'1');assert.equal(select.disabled,false);
 bindCustomerSelector(root,state,async id=>{selectCustomerWorkspace(state,id);return true;});
 select.value='2';await change();assert.equal(select.value,'2');assert.equal(state.company,2);assert.equal(select.disabled,false);
});
