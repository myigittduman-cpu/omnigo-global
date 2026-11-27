import {html,t} from './i18n.js';
import {esc,money} from './ui.js';
import {paymentIsApproved} from './payment-approval.js';

/** A marketplace-confirmed estimate is not an administrator-approved receipt. */
export function paymentAmount(amount,approved=false){
 return html`<span class="payment-amount-line">${money(amount)}${approved?html`<span class="payment-approved-check" role="img" aria-label="${esc(t('Hesaba geçtiği yönetici tarafından onaylandı'))}" title="${esc(t('Hesaba geçtiği yönetici tarafından onaylandı'))}">✓</span>`:''}</span>`;
}

export function receivedPaymentAmount(payment){
 return paymentAmount(payment.actualCents,payment.status==='paid'&&!payment.integration&&paymentIsApproved(payment));
}
