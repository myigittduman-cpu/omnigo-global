import {html} from './i18n.js';
import {money} from './ui.js';
import {upcomingPaymentTotals} from './upcoming-payment-model.js';

export function paymentSummaryCards(summary,forecast){
 const {estimatedCents,confirmedCents}=summary&&forecast?upcomingPaymentTotals(forecast):summary||{};
 const display=cents=>summary?money(cents):'—';
 return html`<div class="report-metrics" ${summary?'':'aria-busy="true" inert'}>
  <article><span>Tahmini / teyitsiz bekleyen</span><strong data-payment-estimated-total>${display(estimatedCents)}</strong><small>${forecast?'Üst tablodaki seçili dönemin tahminleri':'Ödeme tarihi ve tutarı değişebilir'}</small></article>
  <article><span>${forecast?'Teyitli bekleyen':'Panel / ekstre ile teyitli'}</span><strong data-payment-confirmed-total>${display(confirmedCents)}</strong><small>${forecast?'Üst tablodaki seçili dönemin teyitli kayıtları':'Teyit kaynağı kaydedilmiş ödemeler'}</small></article>
  <article class="payment-received-card" data-pending-payments><span>Aktarımı bildirilen</span><strong>${display(summary?.sentCents||0)}</strong><div class="payment-received-card-footer"><small>Omnigo onayı bekleniyor</small><button type="button" class="text-btn" data-pending-payments aria-label="Beklenen ödemelerin detayları">Detaylar</button></div></article>
  <article class="payment-received-card" data-paid-payments><span>Hesaba geçen</span><strong>${display(summary?.receivedCents)}</strong><div class="payment-received-card-footer"><small>Seçili dönemde Omnigo tarafından onaylanmış</small><button type="button" class="text-btn" data-paid-payments aria-label="Hesaba geçen ödemelerin detayları">Detaylar</button></div></article>
 </div>`;
}

export function refreshPaymentForecastCards(root,forecast){
 const {estimatedCents,confirmedCents}=upcomingPaymentTotals(forecast);
 root.querySelector('[data-payment-estimated-total]').textContent=money(estimatedCents);
 root.querySelector('[data-payment-confirmed-total]').textContent=money(confirmedCents);
}
