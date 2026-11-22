import test from 'node:test';
import assert from 'node:assert/strict';
import {availableNotificationCategories,selectedNotificationCategory} from '../public/notification-category.js';
import {notificationList,notificationControls} from '../public/notification-view.js';
import {notificationEnabled,customerNotificationSettings} from '../public/customer-notifications.js';
test('Bağlantı sekmesi ve ayarı kaldırılır; eski seçim normal bildirimlere döner',()=>{
 const state={user:{role:'admin'},notificationCategory:'connections'};
 assert.ok(!availableNotificationCategories(state).includes('connections'));
 assert.equal(selectedNotificationCategory(state),'marketplaces');assert.equal(notificationEnabled(state,'connections'),false);
 assert.doesNotMatch(notificationControls(state),/Bağlantı Bildirimleri|data-notification-category="connections"/);
 assert.doesNotMatch(customerNotificationSettings({pages:{channels:'shown',orders:'shown'},notifications:{connections:true}}),/data-customer-notification="connections"/);
});
test('Eski bağlantı olayları ön yüzde de gizlenir; sipariş ve hakediş olayları korunur',()=>{
 const html=notificationList({items:[{type:'integration',id:1},{type:'order',id:2,provider:'n11'},{type:'operation',id:3,events:['payment_updated']}]});
 assert.doesNotMatch(html,/integration:1/);assert.match(html,/order:2/);assert.match(html,/operation:3/);
});
