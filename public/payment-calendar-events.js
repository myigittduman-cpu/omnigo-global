import {paymentIsApproved} from './payment-approval.js';

export const isCurrentPayment=item=>!item.integration&&!item.excludedFromTotals&&!item.paymentStateUnknown;
export const pendingPaymentItems=items=>items.filter(item=>isCurrentPayment(item)&&!paymentIsApproved(item));

/** Project the same approved estimates used by the upper forecast into calendar days. */
export function paymentCalendarEvents(report){
 const recorded=pendingPaymentItems(report.items??[]).filter(item=>item.inCalendar);
 if(!report.upcoming)return recorded;
 const inRange=item=>item.date>=report.from&&item.date<=report.to&&(!report.channel||report.channel==='all'||item.channel===report.channel);
 const sources=new Map(recorded.map(item=>[item.id,item]));
 const key=(channel,date)=>channel+'\0'+date;
 const existing=new Set((report.items??[]).filter(item=>['sent','paid'].includes(item.status))
  .map(item=>key(item.channel,item.expectedDate)));
 const forecasts=(report.upcoming.periodItems??report.upcoming.items??[]).filter(item=>inRange(item)&&
  (item.source==='manual'||!existing.has(key(item.channel,item.date))));
 const included=new Set(forecasts.map(item=>key(item.channel,item.date)));
 const independent=recorded.filter(item=>item.status!=='pending'||
  (item.calendarDate<(report.upcoming.today??'')&&!included.has(key(item.channel,item.calendarDate))));
 return [...independent,...forecasts.map(item=>{
  const manual=item.source==='manual'?sources.get(item.id):null;
  return {...manual,id:manual?.id??String(item.id),channel:item.channel,expectedDate:item.date,calendarDate:item.date,
   inCalendar:true,expectedCents:item.amountCents,status:'pending',verification:item.verification,
   forecastSource:item.source,forecastReadOnly:!manual};
 })].sort((a,b)=>a.calendarDate.localeCompare(b.calendarDate)||a.channel.localeCompare(b.channel,'tr'));
}
