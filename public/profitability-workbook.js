import {currentLocale,t} from './i18n.js';
import {profitabilityDocumentTable} from './profitability-document.js';
import {profitabilitySaleAmount,orderMargin} from './profitability-sale-amount.js';
import {profitabilityCommissionSummary} from './profitability-commission-summary.js';
import {profitabilityFeeEntries} from './profitability-fees.js';
import {turkeyWorkbookDate} from './workbook-theme.js';
import {createWorkbook} from './report-xlsx.js';

const currency=value=>Number.isSafeInteger(value)?value/100:null;

export function profitabilityWorkbookTable(rows,channel){
 const document=profitabilityDocumentTable(rows,channel);
 return {name:t('Kârlılık Analizi'),
  head:['Sipariş no','Sipariş tarihi','Pazaryeri','Ürün','Adet','Satış tutarı (TL)','Alıcının ürün tutarı (TL)',
   'Komisyon (TL)','Komisyon oranı (%)','Komisyon KDV’si (TL)','Hizmet bedeli (TL)','Ödeme hizmet bedeli (TL)',
   'Stopaj (TL)','Kupon hareketi (TL)','Kargo kesintisi (TL)','Alış maliyeti (TL)','Kâr/zarar (%)','Hesaba geçecek tutar (TL)','Açıklama'].map(label=>t(label)),
  money:[5,6,7,9,10,11,12,13,14,15,17],integers:[4],percentages:[8,16],dateTimes:[1],
  rows:rows.map((row,index)=>{
   const products=row.products||[],commission=profitabilityCommissionSummary(row),fees=profitabilityFeeEntries(row);
   const payout=row.afterDeductionsCents??row.reportedPayoutCents;
   const fee=label=>currency(fees.find(entry=>entry.label===label)?.cents);
   const margin=orderMargin(row,currentLocale())===null?null:(payout-row.purchaseCents)/row.salesCents;
   const notes=document.rows[index].flatMap((value,column)=>column>=3&&typeof value==='string'&&value
    ?[document.head[column]+': '+value]:[]).join('\n');
   return [String(row.orderNumber),turkeyWorkbookDate(row.orderedAt),row.channel,document.rows[index][2],
    products.length&&products.every(product=>Number.isSafeInteger(product.quantity))?products.reduce((sum,product)=>sum+product.quantity,0):null,
    currency(profitabilitySaleAmount(row).cents),currency(row.orderPricing?.buyerPaidCents),currency(commission?.commissionCents),
    Number.isSafeInteger(commission?.rateBasisPoints)?commission.rateBasisPoints/10000:null,
    fee('Komisyon KDV’si'),fee('Hizmet bedeli'),fee('Ödeme hizmet bedeli'),fee('Stopaj'),currency(row.statementCouponCents),currency(row.statementShippingCents),
    currency(row.purchaseCents),margin,currency(payout),notes];
  })};
}

export async function createProfitabilityWorkbook(report,rows,channel,logo=null){
 return createWorkbook({...report,previousFrom:'—',previousTo:'—',totals:{orders:rows.length},products:rows.flatMap(row=>row.products||[])},
  [profitabilityWorkbookTable(rows,channel)],[logo]);
}
