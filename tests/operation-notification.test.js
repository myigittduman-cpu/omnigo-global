import test from 'node:test';import assert from 'node:assert/strict';
import {notificationList} from '../public/notification-view.js';
import {operationNotificationDetail,operationNotificationTitle} from '../public/operation-notification.js';
import {orderAlertGroups,orderAlertText} from '../public/order-alert-model.js';
test('İptal ve aktarım bildirimleri zilde ve cihazda doğru başlıkla görünür',()=>{
 const items=[{id:10,type:'operation',provider:'n11',events:['cancelled'],title:'Sipariş iptal edildi',body:'İptal işlendi',company:'Firma',companyId:1},{id:11,type:'shipment',provider:'n11',title:'Müşteri belge yükledi',body:'Belge eklendi',company:'Firma',companyId:1}];
 assert.match(notificationList({items}),/Sipariş iptal edildi/);
 const groups=orderAlertGroups([...items,items[0]]);assert.equal(groups.length,2);assert.match(orderAlertText(groups[0]).title,/iptal edildi/);assert.match(orderAlertText(groups[1]).title,/belge yükledi/);
 const html=operationNotificationDetail({events:['cancelled'],provider:'n11',orderNumber:'<img onerror=bad>',cancelledQuantity:1});assert.ok(!html.includes('<img'));assert.match(html,/İptal edilen adet/);
});
test('Elle girilen hakediş bildirimi pazaryeri adı gerektirmez ve sipariş başlığı kullanmaz',()=>{
 const event={id:12,type:'operation',provider:'portal',companyId:3,company:'Test müşterisi',events:['payment_updated'],title:'Omnigo',body:'Hakediş takvimine ekleme yapıldı.'};
 const groups=orderAlertGroups([event]);assert.equal(groups.length,1);
 assert.deepEqual(orderAlertText(groups[0]),{title:'Omnigo',body:'Test müşterisi · Hakediş takvimine ekleme yapıldı.'});
 assert.equal(operationNotificationTitle(event),'Hakediş bildirimi');
 assert.equal(operationNotificationTitle({events:['cancelled']}),'Sipariş işlemi');
});
