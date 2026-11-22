import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {t as i18nT,text as i18nText,html as i18nHtml} from '../public/i18n.js';
import {canInterruptWithNotification,desktopOperationStatus} from '../public/mobile-notification-policy.js';
import {orderAlertGroups,orderAlertText,orderNotificationPresentation,desktopNotificationPresentation} from '../public/order-alert-model.js';
import {notificationView} from '../public/notification-destination.js';
const alertSource=(await fs.readFile(new URL('../public/order-alerts.js',import.meta.url),'utf8')).replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
const feedSource=(await fs.readFile(new URL('../public/notifications.js',import.meta.url),'utf8')).replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
const events=['integration','operation','shipment','support','user'].map((type,index)=>({id:index+1,type,provider:'n11',companyId:1,company:'Firma',title:'Değişiklik',body:'Ayrıntı',status:'error',events:type==='operation'?['cancelled']:[]}));
function alerts(platform,items){
 const toasts=[],sounds=[],notices=[],listeners={},stored=new Map();let refreshed=0;
 class Notice{static permission='granted';constructor(title){notices.push(title);}close(){}}
 const surface={...platform,Notification:Notice,addEventListener:(name,handler)=>{listeners[name]=handler;},removeEventListener(){}};
 const context=vm.createContext({window:surface,Notification:Notice,document:{hidden:false,addEventListener:(name,handler)=>{listeners[name]=handler;}},navigator:{onLine:true},localStorage:{getItem:k=>stored.get(k)??null,setItem:(k,v)=>stored.set(k,v)},
  AudioContext:class{state='running';currentTime=0;createOscillator(){sounds.push(true);return {frequency:{},connect(){},start(){},stop(){}};}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}};}},
  unlockNotificationSound:async()=>true,previewNotificationSound:async()=>{},playNotificationSound:()=>{sounds.push(true);return true;},setTimeout:()=>1,clearTimeout(){},toast:message=>toasts.push(message),orderAlertGroups,orderAlertText,orderNotificationPresentation,backgroundActive:()=>false,bindBackgroundControls(){},
  desktopOperationStatus,desktopNotificationPresentation,canInterruptWithNotification:type=>canInterruptWithNotification(type,surface),api:async()=>({cursor:99,items}),state:{user:{id:1,role:'admin'}},onArrival:async()=>{refreshed++;}});
 vm.runInContext(alertSource,context);vm.runInContext('startOrderAlerts(state,onArrival)',context);
 return {toasts,sounds,notices,run:()=>listeners.online(),refreshed:()=>refreshed,stored};
}
test('Windows bildirimi stok ve kurulum işlemlerini gerçek olay koduyla açar',async()=>{
 const received=[];
 for(const event of ['inventory_low','inventory_restocked','purchase_price_updated','store_setup_completed','marketplace_import_failed','upcoming_payout_amount_changed']){
  const item={...events[1],events:[event]};
  const result=alerts({__OMNIGO_OPERATION_NOTIFY__:true,__OMNIGO_NOTICE_SCHEMA__:2,__OMNIGO_ORDER_NOTIFY__:payload=>received.push(payload.status)},[item]);
  await result.run();
 }
 assert.deepEqual(received,['inventory_low','inventory_restocked','purchase_price_updated','store_setup_completed','marketplace_import_failed','upcoming_payout_amount_changed']);
 const previous=[];
 const legacy=alerts({__OMNIGO_OPERATION_NOTIFY__:true,__OMNIGO_ORDER_NOTIFY__:payload=>previous.push(payload.status)},[
  {...events[1],id:93,events:['inventory_restocked']},{...events[1],id:94,events:['cancelled']}]);
 await legacy.run();
 assert.deepEqual(previous,['cancelled']);
 assert.equal(legacy.stored.get('omnigo-order-cursor:1'),'99');
 for(const role of ['client','admin']){
  assert.equal(notificationView({type:'operation',events:['store_setup_completed']},role),'overview');
  assert.equal(notificationView({type:'operation',view:'overview'},role),'overview');
  assert.equal(notificationView({type:'user',view:'overview'},role),null);
 }
});
for(const platform of [{__OMNIGO_ANDROID__:true},{__OMNIGO_IOS__:true},{__OMNIGO_MOBILE_PUSH__:()=>{}},{__OMNIGO_BACKGROUND__:()=>{}},{}]){
 test('Mobilde bağlantı ve bilinmeyen olay sessiz kalır; liste yenilemesi ve imleç ilerler '+Object.keys(platform)[0],async()=>{
  const result=alerts(platform,[events[0],{...events[0],id:8,type:'unknown'},{...events[1],events:['transferred']},{...events[1],events:[]},{...events[0],status:'recovered'}]);await result.run();
  assert.equal(result.toasts.length,0);assert.equal(result.sounds.length,0);assert.equal(result.notices.length,0);
  assert.equal(result.refreshed(),1);assert.equal(result.stored.get('omnigo-order-cursor:1'),'99');
 });
 test('Mobilde yalnız kullanıcı ve pazaryeri olayları uyarır '+Object.keys(platform)[0],async()=>{
  const result=alerts(platform,[...events,{id:6,type:'order',provider:'n11',companyId:1,company:'Firma'}]);await result.run();
  assert.equal(result.toasts.length,5);assert.ok(result.toasts.some(text=>/Yeni sipariş/.test(text)));assert.equal(result.notices.length,5);assert.equal(result.sounds.length,1);assert.equal(result.refreshed(),1);
 });
}
test('Web görünümünde bağlantı listede kalır, izinli uyarılar gösterilir',async()=>{
 const result=alerts({},events);await result.run();assert.equal(result.toasts.length,4);assert.equal(result.notices.length,4);assert.equal(result.refreshed(),1);
});
for(const mobile of [true,false])test(`Müşteri çözüm kaydı ve sayacı korunur; mobil=${mobile}`,async()=>{
 const toasts=[],surface=mobile?{__OMNIGO_ANDROID__:true}:{};
 const state={user:{id:2,role:'client'},company:1,notificationData:{through:{resolved:1}}};
 const data={items:[{type:'resolved',id:2,targetId:5}],through:{resolved:2},counts:{unread:1},totalUnread:1};
 const context=vm.createContext({i18nT,i18nText,i18nHtml,document:{createElement:()=>({}),head:{append(){}}},workspaceToken:()=>({company:1}),sameWorkspace:()=>true,selectedNotificationCategory:()=> 'resolved',api:async()=>data,toast:message=>toasts.push(message),canInterruptWithNotification:type=>canInterruptWithNotification(type,surface),state});
 vm.runInContext(feedSource,context);await vm.runInContext('loadNotifications(state)',context);
 assert.equal(state.notificationData,data);assert.equal(state.notificationData.totalUnread,1);assert.equal(toasts.length,1);
});
