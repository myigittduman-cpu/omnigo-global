import {labelMap as localizedLabels} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {date,esc,money,platform} from './ui.js';
import {paymentState} from './payment-approval.js';
import {paymentAmount} from './payment-amount.js';

// Use the same calendar dates and monetary values as the day cells.
// A planned payment stays pending even when its date is in the past.
export function paymentBreakdownGroups(items,selected=''){
 const days=new Map();
 for(const payment of items){
  if(payment.excludedFromTotals||!payment.inCalendar||(selected&&payment.calendarDate!==selected))continue;
  if(!days.has(payment.calendarDate))days.set(payment.calendarDate,new Map());
  const channels=days.get(payment.calendarDate);
  if(!channels.has(payment.channel))channels.set(payment.channel,{channel:payment.channel,amounts:new Map()});
  const group=channels.get(payment.channel);
  const kind=paymentState(payment);
  const amount=payment.status==='paid'?payment.actualCents:payment.expectedCents;
  group.amounts.set(kind,(group.amounts.get(kind)||0)+amount);
 }
 return [...days].sort(([a],[b])=>a.localeCompare(b)).map(([day,channels])=>({day,channels:[...channels.values()]}));
}

const amountLabels=localizedLabels({estimated:'Tahmini beklenen',confirmed:'Teyitli beklenen',sent:'Aktarımı bildirilen',received:'Hesaba geçen'});

function paymentDayRows(group){
 const rows=group.channels.flatMap(({channel,amounts})=>Object.keys(amountLabels)
  .filter(kind=>amounts.has(kind)).map(kind=>({channel,kind,amount:amounts.get(kind)})));
 return i18nHtml`<tbody>${rows.map(({channel,kind,amount},index)=>i18nHtml`<tr>
  ${index===0?i18nHtml`<th class="payment-breakdown-date" scope="rowgroup" rowspan="${rows.length}"><time datetime="${esc(group.day)}">${esc(date(group.day))}</time></th>`:''}
  <td>${platform(channel)}</td>
  <td><span class="payment-state ${kind}">${amountLabels[kind]}</span></td>
  <td class="payment-breakdown-amount ${kind}">${paymentAmount(amount,kind==='received')}</td>
 </tr>`).join('')}</tbody>`;
}

export function paymentBreakdown(items,selected=''){
 const groups=paymentBreakdownGroups(items,selected);
 const table=groups.length?i18nHtml`<div class="payment-breakdown-scroll corporate-table-viewport" tabindex="0" aria-label="Platformlara göre ödemeler tablosu">
  <table class="corporate-table payment-breakdown-table" aria-labelledby="payment-breakdown-title">
   <thead><tr><th scope="col">Tarih</th><th scope="col">Pazaryeri</th><th scope="col">Durum</th><th scope="col">Tutar</th></tr></thead>
   ${groups.map(paymentDayRows).join('')}
  </table>
 </div>`:i18nHtml`<p class="payment-breakdown-empty">${selected?i18nText`${esc(date(selected))} için ödeme kaydı bulunmuyor.`:i18nT('Bu ay için ödeme kaydı bulunmuyor.')}</p>`;
 return i18nHtml`<section class="payment-breakdown" aria-labelledby="payment-breakdown-title"><h3 id="payment-breakdown-title">Platformlara göre ödemeler</h3>${table}</section>`;
}
