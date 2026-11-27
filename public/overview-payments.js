import {html,t} from './i18n.js';
import {date,esc,icon,money,platform} from './ui.js';
import {currentPaymentForecast} from './upcoming-payment-model.js';
import {currentMonthCardBorder,periodChange} from './sales-period-metrics.js';

export function overviewPaymentPeriods(periods,summaryCards){
 return html`<section class="sales-period-metrics overview-payment-periods" aria-label="Aylık hakediş özeti">
  ${[['month','Bu Ay'],['previousMonth','Geçen Ay']].map(([key,label])=>{
   const period=periods[key];
   return html`<article class="panel sales-period-card">${currentMonthCardBorder(key)}<header><h3>${t(label)}</h3><span>${date(period.from)} – ${date(period.to)}</span></header>
    <dl><div><dt>Hesaba geçen hakediş</dt><dd>${money(period.paidCents)}${key==='month'?periodChange(period.paidCents,periods.previousMonth.paidCents):''}</dd></div></dl>
   </article>`;
  }).join('')}
  <div class="overview-payment-pair">${summaryCards}</div>
 </section>`;
}

export function overviewPaymentForecast(data){
 return currentPaymentForecast({upcoming:data.upcoming,marketplaces:data.channels?.map(channel=>channel.name)});
}

export function overviewExpectedThisMonth(forecast){
 const today=forecast?.today;
 if(!today)return null;
 const current=forecast.months.find(({month})=>month===today.slice(0,7));
 return (current?.items??[]).reduce((total,{date,amountCents})=>date>=today?total+amountCents:total,0);
}

export function overviewPayments(model){
 const rows=(model?.rows??[]).flatMap(({name,payments})=>[...payments.values()].flatMap(entries=>entries.map(entry=>({name,...entry}))))
  .sort((a,b)=>a.date.localeCompare(b.date)||a.name.localeCompare(b.name,'tr'));
 return html`<div class="section-heading"><h2>Yaklaşan ödemeler</h2><button type="button" class="text-btn" data-nav="payments">Tüm hakedişler ${icon('arrow')}</button></div>
 <section class="panel overview-payments" aria-label="Yaklaşan ödemeler">
  ${model?.period?html`<p class="panel-note">${date(model.period.start)} – ${date(model.period.end)}</p>`:''}
  ${rows.length?html`<div class="table-scroll"><table class="payments-table corporate-table" data-copyable-table><thead><tr><th scope="col">Pazaryeri</th><th scope="col">Ödeme tarihi</th><th scope="col">Durum</th><th scope="col">Tutar</th></tr></thead><tbody>${rows.map(row=>html`<tr>
   <th scope="row">${platform(row.name)}</th><td><time datetime="${esc(row.date)}">${date(row.date)}</time></td><td>${t(row.verification==='confirmed'?'Teyitli':'Tahmini')}</td><td><strong>${money(row.amountCents)}</strong></td>
  </tr>`).join('')}</tbody></table></div>`:html`<p class="report-empty">Yaklaşan ödeme kaydı bulunmuyor.</p>`}
 </section>`;
}
