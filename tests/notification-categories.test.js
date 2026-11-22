import test from 'node:test';
import assert from 'node:assert/strict';
import {notificationList,notificationControls,notificationCount} from '../public/notification-view.js';
import {categoryForNotification} from '../public/notification-category.js';
import {customerNotificationSettings,notificationEnabled} from '../public/customer-notifications.js';
test('Omnigo yeni sipariş bildirimi müşteri menüsünden bağımsız ve mağaza bazında seçilebilir',()=>{
 for(const audience of ['hidden','client','shown']){
  const access={pages:{orders:audience},grants:{orders:{view:false}},notifications:{newOrder:true}};
  const state={user:{role:'admin'},data:{workspaceAccess:access}};
  assert.equal(notificationEnabled(state,'newOrder'),true);
  assert.equal(notificationEnabled({...state,user:{role:'client'}},'newOrder'),false);
  const checkbox=customerNotificationSettings(access).match(/<input[^>]*data-customer-notification="newOrder"[^>]*>/)?.[0];
  assert.ok(checkbox);
  assert.doesNotMatch(checkbox,/disabled/);
  access.notifications.newOrder=false;
  assert.equal(notificationEnabled(state,'newOrder'),false);
 }
});
test('Stok bitiş, geliş, düzeltme ve alış maliyeti kendi sekmesinde, hakediş ve rol sınırı korunur',()=>{
 const zero={id:1,type:'operation',events:['inventory_low'],productTitle:'Örnek ürün',quantity:0};
 assert.equal(categoryForNotification(zero),'stocks');
 for(const event of ['inventory_adjusted','inventory_restocked','purchase_price_updated'])assert.equal(categoryForNotification({type:'operation',events:[event]}),'stocks');
 assert.equal(categoryForNotification({type:'operation',audience:'admin',events:['payment_updated']}),'marketplaces');
 assert.equal(categoryForNotification({type:'operation',audience:'client',events:['payment_updated']}),'payments');
 assert.match(notificationList({items:[zero]},'','all','stocks'),/data-notification="operation:1"/);
 assert.doesNotMatch(notificationList({items:[zero]},'','all','marketplaces'),/data-notification="operation:1"/);
 assert.match(notificationControls({user:{role:'admin'},notificationCategory:'stocks'}),/data-notification-category="stocks" aria-pressed="true"/);
 assert.doesNotMatch(notificationControls({user:{role:'client'}}),/data-notification-category="stocks"/);
 const state={user:{role:'admin'},data:{workspaceAccess:{pages:{inventory:'hidden'},grants:{}}}};
 assert.doesNotMatch(notificationControls(state),/data-notification-category="stocks"/);
 state.notificationData={availableAdminCategories:['marketplaces','stocks']};
 assert.match(notificationControls(state),/data-notification-category="stocks"/);
 assert.doesNotMatch(notificationControls(state),/data-notification-category="users"/);
});

test('Üç kategori türlere göre ayrılır; genel zil seçili sekmeden bağımsızdır',()=>{
 const items=['integration','order','operation','shipment','support','user'].map((type,id)=>({type,id:id+1,company:'Firma',actorName:'<img onerror=alert(1)>',operation:'reply'}));
 const expected={connections:0,marketplaces:2,users:3};
 for(const [category,count] of Object.entries(expected)){
  const html=notificationList({items},'','all',category);
  assert.equal((html.match(/data-notification=/g)||[]).length,count);
  for(const item of items)assert.equal(html.includes(`data-notification="${item.type}:${item.id}"`),item.type!=='integration'&&categoryForNotification(item)===category);
  assert.doesNotMatch(html,/<img onerror/);
 }
 assert.equal(notificationCount({notificationData:{totalUnread:8,counts:{unread:0}}}),8);
});
test('Kategori sayaçları, seçili bölüm ve silme kapsamı görünür',()=>{
 const html=notificationControls({user:{role:'admin'},notificationCategory:'marketplaces',notificationConfirmDelete:true,notificationData:{categoryCounts:{connections:{unread:2},marketplaces:{unread:4},users:{unread:1}},counts:{all:3,unread:2}}});
 for(const name of ['Pazaryeri / Hakediş Bildirimleri','Kullanıcı Bildirimleri'])assert.ok(html.includes(name));
 assert.match(html,/data-notification-category="marketplaces" aria-pressed="true"/);
 assert.match(html,/Bu bölümdeki bildirimler listeden kaldırılacak\. Kaynak kayıtlar korunur/);
 assert.doesNotMatch(html,/Bağlantı Bildirimleri/);assert.match(html,/Bu bölümü temizle/);assert.match(html,/4 okunmamış/);
});

test('Müşteri zilinde siparişler, hakedişler ve destek ayrı sekmelerde görünür',()=>{
 const pages={deliveries:'shown',shipments:'shown',payments:'shown',support:'shown'};
 const grants=Object.fromEntries(Object.keys(pages).map(page=>[page,{view:true}]));
 const state={user:{role:'client'},data:{workspaceAccess:{pages,grants,notifications:{shipmentAdded:true}}}};
 const settings=customerNotificationSettings(state.data.workspaceAccess);
 for(const key of ['deliveryAdded','shipmentAdded'])assert.ok(settings.includes('data-customer-notification="'+key+'"'));
 assert.doesNotMatch(settings,/deliveryCancelled|shipmentCancelled/);
 const controls=notificationControls(state);
 assert.match(controls,/data-notification-category="customer-orders" aria-pressed="true"[^>]*><span>Sipariş bildirimleri/);
 assert.match(controls,/data-notification-category="payments"[^>]*><span>Hakediş bildirimleri/);
 assert.match(controls,/data-notification-category="resolved"[^>]*><span>Destek Bildirimleri/);
 assert.doesNotMatch(controls,/data-notification-category="marketplaces"/);
 const items=[
  {type:'operation',id:1,events:['client_delivery_added']},
  {type:'operation',id:2,events:['client_shipment_cancelled']},
  {type:'operation',id:3,events:['payment_updated']},
  {type:'resolved',id:4}
 ];
 const orders=notificationList({items},'','all','customer-orders');
 assert.match(orders,/operation:1/);
 assert.match(orders,/notification-cancelled.*operation:2/);
 assert.doesNotMatch(orders,/operation:3|resolved:4/);
 assert.match(notificationList({items},'','all','payments'),/operation:3/);
 assert.match(notificationList({items:[]},'','all','customer-orders'),/Henüz sipariş bildirimi yok\./);
 state.data.workspaceAccess.notifications.shipmentAdded=false;
 assert.match(notificationControls(state),/data-notification-category="customer-orders"/);
 grants.deliveries.view=false;
 pages.shipments='hidden';
 assert.doesNotMatch(notificationControls(state),/data-notification-category="customer-orders"/);
 assert.doesNotMatch(notificationControls({user:{role:'admin'}}),/data-notification-category="customer-orders"/);
});
