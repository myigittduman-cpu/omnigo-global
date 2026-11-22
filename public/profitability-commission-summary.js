const activeStatuses = new Set(['financials_pending', 'reconciled', 'statement_stale']);
const nonnegativeInteger = value => Number.isSafeInteger(value) && value >= 0 ? value : null;

function commissionBasis(order, source) {
  const sales = nonnegativeInteger(order.salesCents);
  if (sales === null) return null;
  const pricing = order.orderPricing || {};
  const store = nonnegativeInteger(pricing.storePriceCents);
  const sellerDiscount = nonnegativeInteger(pricing.sellerDiscountCents);
  if (source === 'manual') return sales;
  // PTT quotes the fee on the pre-discount amount. That may differ from recorded sales.
  if (source === 'marketplace_rate' && order.channel === 'PTT AVM') return store;
  if (order.channel === 'n11' && ['marketplace_rate', 'marketplace_line_rates'].includes(source)) return sales;
  if (source === 'marketplace_effective' && order.channel === 'idefix') {
    // The order projection currently retains the fee, but not which of these bases matched earnings.
    return store !== null && sellerDiscount !== null && store - sellerDiscount === sales ? sales : null;
  }
  if (source === 'marketplace_sale') {
    // A Trendyol movement may use either the original or the paid merchandise price.
    return store === sales ? sales : null;
  }
  if (source === 'marketplace_effective' && ['Koçtaş', 'Pazarama'].includes(order.channel)) return sales;
  return null;
}

// Match ProfitabilityCommission::amount without multiplying two large integers as a float.
function roundedCommission(cents, points) {
  return Math.floor(cents / 10000) * points + Math.floor(((cents % 10000) * points + 5000) / 10000);
}

/** Order-level intermediate amount, before any other fees, withholding or payout adjustments. */
export function profitabilityCommissionSummary(order) {
  if (!order || !activeStatuses.has(order.status)) return null;
  const source = order.commissionSource || null;
  const points = nonnegativeInteger(order.commissionRateBasisPoints);
  const rateBasisPoints = points !== null && points <= 10000 ? points : null;
  let commissionCents = nonnegativeInteger(order.commissionCents);
  if (commissionCents === null && rateBasisPoints === null) return null;
  const basisCents = commissionBasis(order, source);
  // n11's single reported rate is applied once to the complete matched invoice amount.
  // Other providers may round each line, blend SKU rates or report a VAT-exclusive fee.
  const campaignDiscount = order.commissionDiscountCents ?? order.orderPricing?.commissionDiscountCents;
  const quotedN11Rate = source === 'marketplace_rate' && order.channel === 'n11';
  const hasN11Campaign = order.channel === 'n11' && (quotedN11Rate || source === 'marketplace_line_rates')
    && campaignDiscount != null && campaignDiscount !== 0;
  const canApplyRate = quotedN11Rate && basisCents !== null && rateBasisPoints !== null
    && !hasN11Campaign;
  const fromRate = canApplyRate ? roundedCommission(basisCents, rateBasisPoints) : null;
  if (commissionCents === null) commissionCents = fromRate;
  const rateAgrees = !hasN11Campaign && (fromRate === null || commissionCents === fromRate);
  const remainingCents = basisCents !== null && basisCents === order.salesCents && commissionCents !== null && rateAgrees
    ? basisCents - commissionCents : null;
  return {basisCents, rateBasisPoints, commissionCents, remainingCents, source};
}
