import {currentLocale,html,t,text} from './i18n.js';
import {date,money} from './ui.js';

const periods=[['month','Bu Ay'],['previousMonth','Geçen Ay'],['threeMonths','Son 3 Ay']];
const fields=[
 ['turnoverCents','Toplam ciro',money],
 ['returnCents','Toplam iade',money],
 ['orders','Toplam sipariş',value=>value.toLocaleString(currentLocale())],
 ['averageCents','Sipariş başına satış',money]
];
const month=value=>new Intl.DateTimeFormat(currentLocale(),{
 month:'long',year:'numeric',timeZone:'Europe/Istanbul'
}).format(new Date(value+'T12:00:00+03:00'));

export function periodChange(current,previous){
 if(!Number.isSafeInteger(current)||!Number.isSafeInteger(previous)||previous<=0)return '';
 const ratio=(current-previous)/previous;
 const label=new Intl.NumberFormat(currentLocale(),{style:'percent',signDisplay:'exceptZero',maximumFractionDigits:1}).format(ratio);
 return html`<small class="period-change ${ratio<0?'period-change-down':ratio>0?'period-change-up':''}" title="${t('Geçen aya göre')}" aria-label="${text`Geçen aya göre ${label}`}">${label}</small>`;
}

export function salesPeriodMetrics(metrics){
 return html`<section class="sales-period-metrics" aria-label="Dönemlere göre satış özeti">${periods.filter(([key])=>metrics[key]).map(([key,label])=>{
  const period=metrics[key];
  const previous=key==='month'&&!period.partial&&!metrics.previousMonth?.partial?metrics.previousMonth:null;
  return html`<article class="panel sales-period-card">
   <header><h3>${t(label)}</h3><span>${date(period.from)} – ${date(period.to)}</span></header>
   <dl>${fields.map(([field,name,format])=>html`<div><dt>${t(name)}</dt><dd>${period[field]==null?'—':format(period[field])}${periodChange(period[field],previous?.[field])}</dd></div>`).join('')}</dl>
   ${period.coverageFrom?html`<p class="sales-period-coverage">Aktarım başlangıcı · ${month(period.coverageFrom)}</p>`:''}
   ${period.partial?html`<p class="sales-period-coverage">Kısmi aktarım · Mevcut kayıtlar</p>`:''}
  </article>`;
 }).join('')}</section>`;
}
