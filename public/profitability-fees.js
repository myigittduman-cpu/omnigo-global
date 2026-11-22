const fields = [
  ['Komisyon KDV’si', 'commissionTaxCents', 'Commission', 'taxCents'],
  ['Hizmet bedeli', 'serviceCents', 'ProcessingFeeExpense', 'grossCents'],
  ['Ödeme hizmet bedeli', 'paymentServiceCents', 'PaymentServiceCostReflection', 'grossCents'],
  ['Stopaj', 'withholdingCents'],
];

export function profitabilityFeeEntries(order) {
  const entries = [];
  for (const [label, key, type, amountKey] of fields) {
    if (Number.isSafeInteger(order[key])) {
      entries.push({label, cents: order[key], fromStatement: false});
      continue;
    }
    if (!type) continue;
    const matching = (order.statementItems || []).filter(item => item.type === type);
    if (matching.length && matching.every(item => Number.isSafeInteger(item[amountKey]))) {
      entries.push({label, cents: -matching.reduce((sum, item) => sum + item[amountKey], 0), fromStatement: true});
    }
  }
  return entries;
}
