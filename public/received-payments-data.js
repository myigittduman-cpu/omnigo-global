import {paymentIsApproved,paymentIsReported} from './payment-approval.js';
import {isCurrentPayment,paymentCalendarEvents,pendingPaymentItems} from './payment-calendar-events.js';

export const receivedPaymentsPerPage=10;
const inPeriod=(report,day)=>day>=report.from&&day<=report.to;
const reportDate=item=>item.status==='paid'?item.paidDate:item.expectedDate;

export function paymentReceiptDifference(payment){
 if(payment.status!=='paid'||!Number.isSafeInteger(payment.actualCents)||!Number.isSafeInteger(payment.expectedCents))return 0;
 return payment.actualCents-payment.expectedCents;
}

// Use the bank receipt date, exactly as the period's received total does.
export function receivedPaymentItems(report){
 return (report.items||[]).filter(item=>isCurrentPayment(item)&&item.status==='paid'&&paymentIsApproved(item)&&inPeriod(report,item.paidDate))
  .sort((a,b)=>b.paidDate.localeCompare(a.paidDate)||Number(b.id)-Number(a.id));
}

export function expectedPaymentItems(report){
 return pendingPaymentItems(report.items||[]).sort((a,b)=>(a.expectedDate||a.paidDate||'').localeCompare(b.expectedDate||b.paidDate||'')||Number(a.id)-Number(b.id));
}

export function reportedPaymentItems(report){
 return (report.items||[]).filter(item=>isCurrentPayment(item)&&paymentIsReported(item)&&inPeriod(report,reportDate(item)))
  .sort((a,b)=>reportDate(b).localeCompare(reportDate(a))||Number(b.id)-Number(a.id));
}

export function paymentListItems(report,tab){
 if(tab==='calendar')return paymentCalendarEvents(report);
 if(tab==='reported')return reportedPaymentItems(report);
 return tab==='pending'?expectedPaymentItems(report):receivedPaymentItems(report);
}

export function applyPaymentApproval(report,updated){
 const record=report.items.find(item=>item.id===updated.id);
 if(!record||record.version>updated.version||!paymentIsApproved(updated))return false;
 Object.assign(record,updated);
 if(report.summary){
  report.summary.sentCents=reportedPaymentItems(report).reduce((sum,item)=>sum+(item.status==='paid'?item.actualCents:item.expectedCents),0);
  report.summary.receivedCents=receivedPaymentItems(report).reduce((sum,item)=>sum+item.actualCents,0);
 }
 for(const field of ['periodItems','items']){
  if(report.upcoming?.[field])report.upcoming[field]=report.upcoming[field].filter(item=>
   item.id!==updated.id&&(item.source==='manual'||item.channel!==updated.channel||item.date!==updated.expectedDate));
 }
 return true;
}

export function receivedPaymentPage(report,requestedPage=1,tab='received'){
 const rows=paymentListItems(report,tab),total=rows.length,totalPages=Math.max(1,Math.ceil(total/receivedPaymentsPerPage));
 const value=Number(requestedPage),page=Math.min(totalPages,Math.max(1,Number.isFinite(value)?Math.trunc(value):1));
 const offset=(page-1)*receivedPaymentsPerPage;
 return {items:rows.slice(offset,offset+receivedPaymentsPerPage),page,totalPages,total,from:total?offset+1:0,to:Math.min(offset+receivedPaymentsPerPage,total)};
}

