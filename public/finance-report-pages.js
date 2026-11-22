export const financeReportPages = Object.freeze({
  financeProfitability: { label: 'Kârlılık Analizi', endpoint: '/finance/profitability', icon: 'analytics', pageSize: 10 },
});

export const financeReportKeys = Object.freeze(Object.keys(financeReportPages));
export const isFinanceReportPage = page => Object.hasOwn(financeReportPages, page);
