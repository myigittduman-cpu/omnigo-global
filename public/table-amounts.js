import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {cancellationState} from './order-cancellation.js';
import {orderAmountView} from './order-pricing.js';
import {shipmentRowStatus} from './shipment-status.js';
import {money} from './ui.js';

// Display-only projections. Never overwrite stored amounts or feed report totals.
export function orderTableAmountView(order){
 const cancellation=cancellationState(order);
 if(cancellation&&(!cancellation.partial||cancellation.operationalOnly))return '—';
 const partial=!!cancellation?.partial;
 const amount=order.grossCents-order.cancelCents;
 const net=amount-order.returnCents;
 const primary=partial?i18nHtml`<strong>${money(amount)}</strong><small>İptal sonrası tutar</small>`:orderAmountView(order);
 return `${primary}${order.returnCents?i18nHtml`<small>İade: ${money(order.returnCents)}</small>`:''}${order.returnCents?i18nHtml`<small>Kalan: ${money(net)}</small>`:''}`;
}

export function shipmentAmountHidden(row){
 const cancellation=cancellationState(row);
 return shipmentRowStatus(row)==='cancelled'||!!(cancellation&&!cancellation.partial);
}
export function shipmentTableAmountCents(row){
 if(shipmentAmountHidden(row))return null;
 return Number.isSafeInteger(row.invoiceCents)?row.invoiceCents:null;
}
