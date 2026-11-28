import {brandedDocumentFilename} from './document-filename.js';
import {t,text} from './i18n.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {exportCompanyName} from './company-export-brand.js';
import {selectUpcomingPaymentPeriod,upcomingPaymentMonths,upcomingPaymentPeriods} from './upcoming-payment-periods.js';

const marketplaceOrder=Object.freeze(['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol']);
const payable=item=>Number.isSafeInteger(item.amountCents)&&item.amountCents>0;

export function upcomingPaymentTableRows(model,channel){
 if(channel!=='all')return model.rows;
 const rows=new Map(model.rows.map(row=>[row.name,row]));
 for(const name of marketplaceOrder)if(marketplaceVisible(name)&&!rows.has(name))rows.set(name,{name,payments:new Map()});
 return [...new Set([...marketplaceOrder,...rows.keys()])].filter(name=>rows.has(name)&&marketplaceVisible(name)).map(name=>rows.get(name));
}

/** Empty marketplace slots are for the screen only. */
export function upcomingPaymentDocumentModel(model){
 const dates=new Set();
 const rows=upcomingPaymentTableRows(model,'all').flatMap(market=>{
  const payments=new Map();
  for(const [date,items] of market.payments){
   const payableItems=items.filter(payable);
   if(!payableItems.length)continue;
   payments.set(date,payableItems);
   dates.add(date);
  }
  return payments.size?[{name:market.name,payments}]:[];
 });
 return {...model,rows,dates:[...dates].sort()};
}

export function upcomingPaymentTitle(company){
 if(!String(company?.name||company?.title||'').trim())return t('Pazaryeri Ödemeleri');
 return text`${exportCompanyName(company)} Pazaryeri Ödemeleri`;
}

export const upcomingPaymentDate=day=>day.split('-').reverse().join('.');

export function upcomingMarketplaceEntries({payments}){
 return [...payments.keys()].sort().flatMap(day=>payments.get(day));
}

export function upcomingPaymentTotals(model){
 const totals={estimatedCents:0,confirmedCents:0};
 for(const {payments} of model.rows){
  for(const entries of payments.values())for(const payment of entries){
   if(payment.paymentApproval?.at)continue;
   const key=payment.verification==='confirmed'?'confirmedCents':'estimatedCents';
   totals[key]+=payment.amountCents;
  }
 }
 return totals;
}

export function upcomingPaymentExpectedCents(model){
 const {estimatedCents,confirmedCents}=upcomingPaymentTotals(model);
 return estimatedCents+confirmedCents;
}

export function currentPaymentForecast(report,selectedStart='',selectedMonths=[]){
 if(!report.upcoming)return null;
 return upcomingPaymentModel(report.upcoming,report.channel,report.marketplaces,report.periodView?report.from:selectedStart,
  new Set(),report.periodView?[]:selectedMonths);
}

export function upcomingPaymentFilename(model,company,downloadedAt=new Date()){
 return brandedDocumentFilename(company,'Ödeme Tablosu','pdf',downloadedAt);
}

export function upcomingPaymentStatus({verification,source}){
 if(verification==='estimated')return t(source==='marketplace_panel'?'Pazaryeri tahmini':'Tahmini beklenen');
 return t(source==='marketplace'?'Pazaryeri teyitli':'Teyitli beklenen');
}

export function upcomingPaymentModel(overview,channel='all',marketplaces=[],selectedStart='',excluded=new Set(),selectedMonths=[]){
 const byMarket=new Map(),dates=new Set();
 const records=overview?.periodItems??overview?.items??[];
 const pending=records.filter(payable);
 const periods=overview?.periods?.map(period=>({...period,items:pending.filter(item=>item.date>=period.start&&item.date<=period.end)}))
  ??upcomingPaymentPeriods(pending,overview?.today);
 const selection=selectUpcomingPaymentPeriod(periods,selectedStart);
 const months=upcomingPaymentMonths(periods,channel);
 const selected=new Set(selectedMonths);
 const includedMonths=months.filter(({month})=>selected.has(month));
 let period=selection.period;
 if(selected.size){
  period=null;
  if(includedMonths.length){
   const first=includedMonths[0],last=includedMonths.at(-1);
   const [year,month]=last.month.split('-').map(Number);
   const items=includedMonths.flatMap(entry=>entry.items);
   period={start:first.month+'-01',end:new Date(Date.UTC(year,month,0)).toISOString().slice(0,10),
    lastPaymentDay:items.at(-1).date,items};
  }
 }
 const items=(period?.items??[]).filter(item=>
  marketplaceVisible(item.channel)&&(channel==='all'||item.channel===channel)&&!excluded.has(item.channel));
 for(const item of items){
  if(!byMarket.has(item.channel))byMarket.set(item.channel,new Map());
  const payments=byMarket.get(item.channel);
  if(!payments.has(item.date))payments.set(item.date,[]);
  payments.get(item.date).push(item);
  dates.add(item.date);
 }
 const names=channel==='all'?[...marketplaces,...byMarket.keys(),...(overview?.channelsWithoutData??[])]:[channel];
 return {
  ...selection,
  period,
  periods,
  months,
  selectedMonths:includedMonths.map(({month})=>month),
  dates:[...dates].sort(),
  rows:[...new Set(names.filter(name=>marketplaceVisible(name)&&!excluded.has(name)))]
   .map(name=>({name,payments:byMarket.get(name)??new Map()})),
  totals:overview?{
   confirmedCents:overview.confirmedCents??overview.remainingCents??0,
   estimatedCents:overview.estimatedCents??0,
   paidThisMonthCents:overview.paidThisMonthCents??0,
  }:null,
  today:overview?.today??null,
 };
}
