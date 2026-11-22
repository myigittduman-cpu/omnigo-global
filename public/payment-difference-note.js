import {html} from './i18n.js';
import {money} from './ui.js';
import {paymentReceiptDifference} from './received-payments-data.js';

export function paymentDifferenceNote(payment){
 const difference=paymentReceiptDifference(payment);
 return difference?html`<small class="payment-difference-note">${difference<0?html`Planlanandan ${money(-difference)} eksik.`:html`Planlanandan ${money(difference)} fazla.`}</small>`:'';
}
