import {cancelledTurnoverValue,customerTurnover,rawTurnoverValue,turnoverValue,salesTurnoverVisible} from './sales-turnover.js';

export function analysisMeasures(totals,refundsVerified=true,visibility={}){
 const turnover=rawTurnoverValue(totals);
 const orders=Number.isSafeInteger(totals?.orders)?totals.orders:null;
 const cancelled=visibility.cancellations===false?0:totals?cancelledTurnoverValue(totals):null;
 const returned=visibility.returns===false?0:totals?.returnCents;
 const afterCancellations=customerTurnover(totals)&&totals.turnoverDisplayBasis!=='after_cancellations'
  ?Number.isSafeInteger(totals?.salesTurnoverCents)?totals.salesTurnoverCents:null:turnoverValue(totals);
 return {
  turnover,
  afterCancellations,
  orders,
  average:turnover===0&&orders===0?0:turnover!==null&&orders>0?Math.round(turnover/orders):null,
  deductions:(refundsVerified||visibility.returns===false)&&cancelled!==null&&Number.isSafeInteger(returned)?cancelled+returned:null,
  units:Number.isSafeInteger(totals?.soldUnits)?totals.soldUnits:null,
 };
}

export const showAfterCancellationTurnover=report=>report?.turnoverDisplayBasis!=='after_cancellations'&&salesTurnoverVisible(report)&&
 (report.sensitiveVisibility?.afterCancellationTurnover??!customerTurnover(report));

export function analysisDeductionLabel(report){
 if(!salesTurnoverVisible(report))return null;
 const fallback=!customerTurnover(report);
 const cancellations=report.sensitiveVisibility?.cancellations??fallback;
 const returns=report.sensitiveVisibility?.returns??fallback;
 if(cancellations&&returns)return 'İptal & İade';
 return cancellations?'İptal':returns?'İade':null;
}
