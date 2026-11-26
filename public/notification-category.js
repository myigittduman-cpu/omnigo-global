import {notificationAvailable,notificationEnabled} from './customer-notifications.js';
import {customerOrderEvent,customerOrderOptions} from './customer-order-notifications.js';
import {labelMap as localizedLabels} from './i18n.js';

export const notificationCategories=localizedLabels({
 marketplaces:'Pazaryeri Bildirimleri',
 'customer-orders':'Sipariş bildirimleri',
 payments:'Hakediş bildirimleri',
 setup:'Kurulum bildirimi',
 stocks:'Stok Bildirimleri',
 users:'Kullanıcı Bildirimleri',
 resolved:'Destek Bildirimleri'
});
export const notificationCategoryLabel=(_state,id)=>notificationCategories[id];
const customerOrderKeys=Object.keys(customerOrderOptions);
export function availableNotificationCategories(state){
 const admin=state.user?.role==='admin',client=state.user?.role==='client';
 const has=key=>notificationEnabled(state,key);
 const adminCategories=admin?state.notificationData?.availableAdminCategories:null;
 const globalOrLocal=(category,fallback)=>Array.isArray(adminCategories)?adminCategories.includes(category):fallback;
 const visible={
  marketplaces:admin,
  'customer-orders':client&&customerOrderKeys.some(key=>notificationAvailable(state,key)),
  payments:admin?globalOrLocal('payments',has('payments')):client&&has('payments'),
  setup:Boolean(state.data?.workspaceAccess?.setupOptions),
  stocks:admin&&globalOrLocal('stocks',has('stock')),
  users:admin&&globalOrLocal('users',has('users')||has('support')||has('shipment')),
  resolved:client&&has('support')
 };
 return Object.keys(notificationCategories).filter(key=>visible[key]);
}
export function selectedNotificationCategory(state){
 const allowed=availableNotificationCategories(state);
 return allowed.includes(state.notificationCategory)?state.notificationCategory:allowed[0]||'all';
}
export function categoryForNotification(item){
 if(item.type==='operation'){
  if(item.events?.includes('store_setup_completed'))return 'setup';
  if(item.events?.some(key=>['inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated'].includes(key)))return 'stocks';
  if(customerOrderEvent(item))return 'customer-orders';
  if(item.events?.some(event=>['payment_updated','upcoming_payout_amount_changed'].includes(event)))return 'payments';
 }
 return item.type==='resolved'?'resolved':item.type==='integration'?'connections':['support','shipment','user'].includes(item.type)?'users':'marketplaces';
}
