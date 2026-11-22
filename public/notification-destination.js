// Resolve notification destinations without broadening the account's page permissions.
import {customerOrderView} from './customer-order-notifications.js';
const pages={
 admin:new Set(['orders','shipments','support','payments','returns','inventory','connections','overview']),
 client:new Set(['orders','shipments','deliveries','support','payments','inventory','overview'])
};
export function notificationView(detail={},role,defaultView='orders'){
 const allowed=pages[role];if(!allowed)return null;
 let requested=customerOrderView(detail)||detail.view;
 if(!requested&&typeof detail.url==='string'&&detail.url.startsWith('/?')){
  try{requested=new URL(detail.url,'https://notification.invalid').searchParams.get('view');}catch{}
 }
 if(requested==='overview'&&detail.type!=='operation')return null;
 if(requested)return allowed.has(requested)?requested:null;
 const events=Array.isArray(detail.events)?detail.events:[];
 const inferred=events.includes('store_setup_completed')?'overview':events.some(event=>['payment_updated','upcoming_payout_amount_changed'].includes(event))?'payments':events.some(event=>['inventory_low','inventory_restocked','inventory_adjusted','purchase_price_updated','marketplace_import_started','marketplace_import_completed','marketplace_import_failed'].includes(event))?'inventory':events.includes('return_updated')?'returns':
  ['support','user','resolved'].includes(detail.type)?'support':detail.type==='shipment'?'shipments':detail.type==='integration'?'connections':detail.type?'orders':defaultView;
 return inferred===defaultView||allowed.has(inferred)?inferred:null;
}
export const hasNotificationDetail=detail=>Number.isSafeInteger(Number(detail?.noticeId))&&Number(detail.noticeId)>0;
