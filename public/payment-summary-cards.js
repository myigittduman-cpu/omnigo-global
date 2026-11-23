import {html} from './i18n.js';
import {money} from './ui.js';
import {upcomingPaymentExpectedCents} from './upcoming-payment-model.js';

export function paymentSummaryCards(summary,forecast){
 const expectedCents=summary&&forecast?upcomingPaymentExpectedCents(forecast):(summary?.estimatedCents??0)+(summary?.confirmedCents??0);
 const display=cents=>summary?money(cents):'—';
 return html`<div class="report-metrics" ${summary?'':'aria-busy="true" inert'}>
  <article><span>Tahmini hakediş</span><strong data-payment-forecast-total>${display(expectedCents)}</strong><small>${forecast?'Üst tablodaki seçili dönemin toplamı':'Ödeme tarihi ve tutarı değişebilir'}</small></article>
  <article class="payment-received-card" data-pending-payments><span>Aktarımı bildirilen</span><strong>${display(summary?.sentCents||0)}</strong><div class="payment-received-card-footer"><small>Omnigo onayı bekleniyor</small><button type="button" class="text-btn" data-pending-payments aria-label="Beklenen ödemelerin detayları">Detaylar</button></div></article>
  <article class="payment-received-card" data-paid-payments><span>Hesaba geçen</span><strong>${display(summary?.receivedCents)}</strong><div class="payment-received-card-footer"><small>Seçili dönemde Omnigo tarafından onaylanmış</small><button type="button" class="text-btn" data-paid-payments aria-label="Hesaba geçen ödemelerin detayları">Detaylar</button></div></article>
 </div>`;
}

export function refreshPaymentForecastCards(root,forecast){
 root.querySelector('[data-payment-forecast-total]').textContent=money(upcomingPaymentExpectedCents(forecast));
}
