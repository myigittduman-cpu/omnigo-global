import {shiftCalendarDay,validCalendarDate} from './calendar-date.js';
import {marketplaceVisible} from './marketplace-visibility.js';

const periodDays=15;

export function upcomingPaymentPeriods(items=[],today){
 const payments=items.filter(item=>validCalendarDate(item?.date)&&marketplaceVisible(item.channel))
  .sort((a,b)=>a.date.localeCompare(b.date));
 const periods=[];
 for(const payment of payments){
  let period=periods.at(-1);
  if(!period||payment.date>period.end){
   period={start:payment.date,end:shiftCalendarDay(payment.date,periodDays-1),lastPaymentDay:payment.date,items:[]};
   periods.push(period);
  }
  period.items.push(payment);
  period.lastPaymentDay=payment.date;
 }
 if(!periods.length)return periods;
 const current=validCalendarDate(today)?Math.max(0,periods.findLastIndex(period=>period.start<=today)):0;
 return [periods[current],...periods.slice(0,current).reverse(),...periods.slice(current+1)];
}

export function selectUpcomingPaymentPeriod(periods,selectedStart){
 if(!periods.length)return {period:null,page:0,pages:0};
 const selected=Math.max(0,periods.findIndex(period=>period.start===selectedStart));
 return {period:periods[selected],page:selected+1,pages:periods.length};
}

export function upcomingPaymentMonths(periods,channel='all'){
 const months=new Map();
 const chronologicalPeriods=[...periods].sort((a,b)=>a.start.localeCompare(b.start));
 for(const period of chronologicalPeriods){
  for(const payment of period.items){
   if(channel!=='all'&&payment.channel!==channel)continue;
   const month=payment.date.slice(0,7);
   if(!months.has(month))months.set(month,[]);
   months.get(month).push(payment);
  }
 }
 return Array.from(months,([month,items])=>({month,items}));
}
