import {currentLocale,html,t,text} from './i18n.js';
import {calendarMonths} from './calendar-date.js';
import {esc,money,date,now} from './ui.js';
export function paymentRangeMonths(from,to){
 const result=[];
 if(!/^\d{4}-\d{2}-\d{2}$/.test(from)||!/^\d{4}-\d{2}-\d{2}$/.test(to)||from>to)return result;
 for(let day=new Date(from+'T12:00:00Z'),i=0;day.toISOString().slice(0,10)<=to&&i<366;day.setUTCDate(day.getUTCDate()+1),i++){
  const key=day.toISOString().slice(0,10),month=key.slice(0,7);
  if(result.at(-1)?.month!==month)result.push({month,offset:(day.getUTCDay()+6)%7,days:[]});
  result.at(-1).days.push(key);
 }
 return result;
}
export function paymentRangeGrid(report,selected,events,{canManage=false}={}){
 const summaries=new Map(),today=now();
 const compact=new Intl.NumberFormat(currentLocale(),{style:'currency',currency:'TRY',notation:'compact',maximumFractionDigits:1});
 for(const payment of events){
  const summary=summaries.get(payment.calendarDate)||{count:0,amount:0};
  summary.count++;summary.amount+=payment.status==='paid'?payment.actualCents:payment.expectedCents;
  summaries.set(payment.calendarDate,summary);
 }
 const empty='<span class="calendar-gap" aria-hidden="true"></span>';
 const weekdays=['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'].map(day=>html`<span class="calendar-weekday">${t(day)}</span>`).join('');
 return paymentRangeMonths(report.from,report.to).map(group=>{
  const title=calendarMonths[Number(group.month.slice(5))-1]+' '+group.month.slice(0,4);
  const count=group.days.reduce((sum,key)=>sum+(summaries.get(key)?.count||0),0);
  const remaining=(7-(group.offset+group.days.length)%7)%7;
  return html`<section class="payment-calendar-month">
   <header><h3>${esc(title)}</h3><span>${text`${count} kayıt`}</span></header>
   <div class="payment-calendar" role="group" aria-label="${esc(title)}">
    ${weekdays}${empty.repeat(group.offset)}
    ${group.days.map(key=>{
     const payment=summaries.get(key),amount=payment?money(payment.amount):'';
     const label=payment?text`${date(key)} · ${payment.count} ödeme kaydı · ${amount} · Detayları aç`
      :text`${date(key)} · ${canManage?t('Hakediş ekle'):t('Ödeme kaydı yok')}`;
     return html`<button type="button" data-payment-day="${key}" class="calendar-day ${key===today?'today':''} ${selected===key?'selected':''} ${payment?'has-payments':''}"
      aria-pressed="${selected===key}" ${key===today?'aria-current="date"':''} aria-label="${esc(label)}" title="${esc(label)}">
      <span class="calendar-day-head"><span class="calendar-day-number">${Number(key.slice(-2))}</span>
       ${payment?html`<small class="calendar-day-count" aria-hidden="true">${payment.count}</small>`
        :canManage?html('<span class="calendar-day-add" aria-hidden="true">+</span>'):''}</span>
      ${payment?html`<span class="calendar-day-amount" aria-hidden="true">${amount}</span><span class="calendar-day-compact" aria-hidden="true">${compact.format(payment.amount/100)}</span>`:''}
     </button>`;
    }).join('')}${empty.repeat(remaining)}
   </div>
  </section>`;
 }).join('');
}
