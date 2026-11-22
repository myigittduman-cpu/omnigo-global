import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {notificationView,hasNotificationDetail} from '../public/notification-destination.js';

test('Bildirim hedefi stok, iade ve hakediş sayfasını korur; müşteri yönetici sayfalarına yönlenmez',()=>{
 for(const view of ['payments','inventory','returns','orders','shipments','support','connections'])assert.equal(notificationView({view,type:'operation'},'admin'),view);
 assert.equal(notificationView({view:'payments',type:'order'},'client'),'payments');
 assert.equal(notificationView({url:'/?view=inventory&orderCompany=2',type:'operation'},'client'),'inventory');
 for(const view of ['returns','connections','customers','https://outside.test'])assert.equal(notificationView({view,type:'operation'},'client'),null);
 assert.equal(notificationView({},'client','channels'),'channels');
 assert.equal(notificationView({events:['payment_updated'],type:'operation'},'client'),'payments');
 assert.equal(hasNotificationDetail({noticeId:0}),false);
 assert.equal(hasNotificationDetail({noticeId:'8'}),true);
});

function handlerFixture(file,{role='client',allowed=['orders','payments','support'],allowDraftLeave=true}={}){
 const source=fs.readFileSync(new URL('../'+file,import.meta.url),'utf8');
 const start=source.indexOf("window.addEventListener('omnigo-order-open'");
 const end=source.indexOf('watchWebVersion();',start);
 const calls=[],state={user:{role,companyId:1},company:1,companies:[{id:1},{id:2}],view:'orders'};
 let handler;
 vm.runInNewContext(source.slice(start,end),{
  window:{addEventListener:(_type,callback)=>handler=callback},state,notificationView,hasNotificationDetail,
  hasWorkspace:()=>true,customerCan:(_state,page)=>allowed.includes(page),
  canLeaveDialogDrafts:async()=>allowDraftLeave,
  document:{querySelector:()=>({close:()=>calls.push('close')})},
  openPushDetail:async detail=>calls.push(['detail',detail.noticeId]),
  navigate:async view=>{calls.push(['navigate',view]);return allowDraftLeave&&allowed.includes(view);},
  changeWorkspace:async(company,options)=>{calls.push(['switch',company,options.view]);return false;},
  notificationDialogClass:()=>null,toast:message=>calls.push(['error',message])
 });
 return {calls,dispatch:detail=>handler({detail})};
}
for(const file of ['public/app.js','public/demo/app.js']){
 test(`${file}: açık müşteri penceresinde hakediş bildirimi siparişlere değil ödemelere gider`,async()=>{
  const fixture=handlerFixture(file);await fixture.dispatch({companyId:1,view:'payments',type:'order',noticeId:0});
  assert.deepEqual(fixture.calls[0],['navigate','payments']);assert.equal(fixture.calls.some(call=>Array.isArray(call)&&call[1]==='orders'),false);
 });
 test(`${file}: ayrıntı mevcut sayfayı korur, kaydedilmemiş veri ve firma sınırı geçerlidir`,async()=>{
  const fixture=handlerFixture(file);await fixture.dispatch({companyId:1,view:'orders',type:'order',noticeId:9});
  assert.deepEqual(fixture.calls,['close',['detail',9]]);
  const blocked=handlerFixture(file,{allowDraftLeave:false});await blocked.dispatch({companyId:1,view:'orders',type:'order',noticeId:9});assert.deepEqual(blocked.calls,[]);
  const other=handlerFixture(file);await other.dispatch({companyId:2,view:'payments',type:'order',noticeId:0});assert.deepEqual(other.calls,[]);
  const hidden=handlerFixture(file);await hidden.dispatch({companyId:1,view:'returns',type:'operation',noticeId:9});assert.deepEqual(hidden.calls,[]);
 });
 test(`${file}: yönetici başka müşteri stok/iade hedefine doğru sayfayla geçer`,async()=>{
  for(const view of ['inventory','returns']){
   const fixture=handlerFixture(file,{role:'admin'});await fixture.dispatch({companyId:2,view,type:'operation',noticeId:9});
   assert.deepEqual(fixture.calls[0],['switch',2,view]);
  }
 });
 test(`${file}: ilk açılış ve service worker hedef sayfasını taşır`,()=>{
  const source=fs.readFileSync(new URL('../'+file,import.meta.url),'utf8');
  assert.match(source,/state\.view=notificationView\(\{view:new URL\(location.href\)\.searchParams\.get\('view'\),type:new URL\(location.href\)\.searchParams\.get\('noticeType'\)\},'admin','orders'\)/);
  assert.match(source,/view:event\.data\.view,noticeId:event\.data\.noticeId/);
 });
}
