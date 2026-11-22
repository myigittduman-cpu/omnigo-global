// One interruption rule for native apps and browsers; recipient checks remain separate.
import {customerOrderEvent} from './customer-order-notifications.js';
const interruptingTypes=new Set(['order','shipment','support','user','resolved']);
const legacyOperations=['cancelled','cancellation_requested','payment_updated','return_updated','dispatched','document_uploaded','document_deleted','inventory_low','inventory_adjusted','purchase_price_updated'];
const additionalOperations=['inventory_restocked','marketplace_import_started','marketplace_import_completed','marketplace_import_failed','upcoming_payout_amount_changed','store_setup_completed'];
export function desktopOperationStatus(notification,format=2){
 if(!Array.isArray(notification?.events))return null;
 const added=additionalOperations.find(event=>notification.events.includes(event));
 if(added)return format===2?added:null;
 return customerOrderEvent(notification)||legacyOperations.find(event=>notification.events.includes(event))||null;
}
export function canInterruptWithNotification(notification){
 const type=typeof notification==='string'?notification:notification?.type;
 if(interruptingTypes.has(type))return true;
 return type==='operation'&&desktopOperationStatus(notification)!==null;
}
