import {esc} from './ui.js';
import {notificationTime} from './notification-time.js';
import {notificationFields} from './notification-detail-view.js';
import {inventoryPriceLabel} from './inventory-price-view.js';

export function inventoryNotificationTitle(event){
 if(event.events?.includes('inventory_restocked'))return 'Stok yeniden geldi';
 if(event.events?.includes('purchase_price_updated'))return 'Alış fiyatı güncellendi';
 if(event.events?.includes('inventory_adjusted')&&Number.isSafeInteger(event.stockDifference)&&event.stockDifference!==0)return 'Pazaryeri / Omnigo stok farkı';
 if(event.events?.includes('inventory_adjusted'))return event.stockMode==='horoz'?'Ortak stok güncellendi':'Stok güncellendi';
 return event.events?.includes('inventory_low')?'Stok bildirimi':'';
}
export function inventoryNotificationDetail(event){
 const price=event.events?.includes('purchase_price_updated');
 const fields=[['Ürün',event.productTitle],['Stok kodu',event.sku],['İşlemi yapan',event.actorName]];
 if(price)fields.push(['Önceki alış fiyatı',event.previousPriceCents==null?'Henüz girilmedi':inventoryPriceLabel(event.previousPriceCents)],['Güncel alış fiyatı',inventoryPriceLabel(event.purchasePriceCents)]);
 else fields.push(['Pazaryeri stoğu',event.marketplaceQuantity],['Stok farkı',event.stockDifference],['Stok modeli',event.stockMode==='horoz'?'Ortak stok':null],['Önceki stok',event.quantityBefore],['Adet değişimi',event.delta],['Güncel stok',event.quantity]);
 return `<section class="notification-detail-summary"><p>${esc(inventoryNotificationTitle(event))}</p>${notificationTime(event.createdAt)}</section>${notificationFields(fields)}`;
}
