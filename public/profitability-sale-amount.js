// Order-line totals can be known before the original offer or the buyer's share is reported.
export function profitabilitySaleAmount(order){
 const offer=order?.orderPricing?.storePriceCents;
 if(Number.isSafeInteger(offer))return {cents:offer,fromOrder:false};
 if(['financials_pending','reconciled','statement_stale'].includes(order?.status)&&Number.isSafeInteger(order.salesCents))
  return {cents:order.salesCents,fromOrder:true};
 return {cents:null,fromOrder:false};
}

export function orderMargin(order,locale){
 const payout=order.afterDeductionsCents??order.reportedPayoutCents;
 if(!['reconciled','financials_pending','statement_stale'].includes(order.status)||
    !Number.isSafeInteger(payout)||!Number.isSafeInteger(order.purchaseCents)||
    !Number.isSafeInteger(order.salesCents)||order.salesCents<=0)return null;
 return new Intl.NumberFormat(locale,{style:'percent',maximumFractionDigits:2}).format((payout-order.purchaseCents)/order.salesCents);
}
