import test from 'node:test';
import assert from 'node:assert/strict';
import {orderAlertGroups,orderAlertText} from '../public/order-alert-model.js';
import {notificationBell,notificationList} from '../public/notification-view.js';
test('Zil tam okunmamış sayıyı gösterir; müşteri yalnız destek çözümü bölümünü görür',()=>{
 for(const count of [1,560,12345])assert.match(notificationBell({user:{role:'admin'},notificationData:{counts:{unread:count}}}),new RegExp(`aria-hidden="true">${count}</span>`));
 assert.match(notificationBell({user:{role:'admin'}}),/notification-count" hidden/);
 const client=notificationBell({user:{role:'client'},notificationData:{counts:{unread:2}}});
 assert.match(client,/Destek Bildirimleri/);assert.match(client,/2 okunmamış/);assert.match(client,/data-notification-category="resolved"/);assert.doesNotMatch(client,/data-notification-category="(?:users|connections)"/);
});
test('Sipariş uyarıları firmayı ve pazaryerini ayırır; aynı olay tek sayılır',()=>{
 const item={id:1,companyId:1,company:'Vizyon',provider:'pazarama'};
 const groups=orderAlertGroups([item,item,{...item,id:2},{...item,id:3,companyId:2},{...item,id:4,provider:'n11'},{...item,id:5,provider:'unknown'}]);
 assert.deepEqual(groups.map(g=>g.count),[2,1,1]);
 assert.deepEqual(orderAlertText(groups[0]),{title:'Pazarama · Yeni sipariş',body:'Vizyon · 2 yeni sipariş geldi.'});
 assert.match(notificationList({items:[{type:'order',id:1,provider:'n11'}]}),/n11’den.*yeni sipariş geldi/s);
});
