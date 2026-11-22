import {loadingState} from './content-state.js';
import {paymentRangeGrid} from './payment-range-grid.js';
import {html as i18nHtml} from './i18n.js';
import {money} from './ui.js';
import {paymentRecords} from './payment-records.js';
import {paymentCalendarEvents} from './payment-calendar-events.js';
import {paymentSummaryCards} from './payment-summary-cards.js';
import {paymentMarketplaceFilter} from './payment-marketplace-filter.js';
import {upcomingPayments} from './upcoming-payments.js';
import {currentPaymentForecast} from './upcoming-payment-model.js';
import {payoutSourceWarning} from './payout-source-warning.js';
export {paymentLabel} from './payment-records.js';
export function calendarShell(admin=false,marketplaces=[],company=null,showForecast=admin){
 return i18nHtml`<section class="reporting" data-reporting="payments">
  ${showForecast?upcomingPayments(undefined,'all',marketplaces,company,'',{canManage:admin,canExport:showForecast}):''}
  ${paymentSummaryCards(null,showForecast)}
  <section class="report-panel payment-calendar-panel" aria-busy="true">
   <div class="report-section-title"><h2>Hakediş takvimi</h2></div>
   ${loadingState('Hakediş takvimi hazırlanıyor…')}
  </section></section>`;
}
export function paymentCalendar(report,selected='',admin=false,company=null,upcomingPeriod='',exportSelection){
 const events=paymentCalendarEvents(report);
 const forecast=currentPaymentForecast(report,upcomingPeriod,exportSelection?.selectedMonths);
 return i18nHtml`${forecast?upcomingPayments(report.upcoming,report.channel,report.marketplaces,company,upcomingPeriod,{...exportSelection,canManage:admin,model:forecast}):''}
 ${paymentSummaryCards(report.summary,forecast)}
 <section class="report-panel payment-calendar-panel">
  <div class="report-section-title"><h2>Hakediş takvimi</h2><div class="payment-calendar-actions">${paymentMarketplaceFilter(report)}${admin?i18nHtml('<button class="btn" type="button" data-calendar-payment-selection>Takvim kayıtlarını yönet</button>'):''}</div></div>
  <p class="report-note">Ödeme kaydını görmek için takvimde bir gün seçin.</p>
  ${admin?payoutSourceWarning(report.upcoming?.sourceWarnings):''}
  ${paymentRangeGrid(report,selected,events,{canManage:admin})}
 </section>
 ${report.undatedItems?.length?i18nHtml`<section class="report-panel"><div class="report-section-title"><h2>Tarih belirlenmedi</h2><strong>${money(report.undatedCents)}</strong></div><p class="report-note">Bu ödemeler seçili döneme veya güne atanmaz ve yukarıdaki dönem toplamlarına dahil değildir. Yönetici tarih eklediğinde ilgili güne taşınır.</p>${paymentRecords(report.undatedItems,admin)}</section>`:""}`;
}

