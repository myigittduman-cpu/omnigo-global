import {brandedDocumentFilename} from './document-filename.js';
import {api} from './api.js';
import {profitabilitySaleAmount,orderMargin} from './profitability-sale-amount.js';
import {profitabilityCommissionSummary} from './profitability-commission-summary.js';
import {profitabilityFeeEntries} from './profitability-fees.js';
import {companyExportLogo} from './company-export-brand.js';
import {openDocumentPrint} from './document-print.js';
import {financeReportPages} from './finance-report-pages.js';
import {currentLocale,t} from './i18n.js';
import {downloadStatus,prepareReportSave,saveReportFile} from './report-download.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';

const changed=()=>new Error(t('Rapor kapsamı değişti. Güncel listeyi açıp yeniden deneyin.'));
const activeStatuses=new Set(['financials_pending','reconciled','statement_stale']);
const currency=value=>Number.isSafeInteger(value)?value/100:null;
const day=value=>new Intl.DateTimeFormat(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(value));
const key=row=>JSON.stringify([row.channel,row.orderNumber,row.orderedAt]);

export async function profitabilityDocumentRows({company,filters,current,read=api}){
 if(!Number.isSafeInteger(Number(company?.id))||Number(company.id)<1)throw changed();
 const rows=[],seen=new Set();
 async function readPage(page){
  if(!current())throw changed();
  const query=new URLSearchParams({...filters,company:company.id,page});
  const result=await read(financeReportPages.financeProfitability.endpoint+'?'+query);
  if(!current()||!result||result.page!==page||!Number.isSafeInteger(result.pageSize)||result.pageSize<1
   ||!Number.isSafeInteger(result.total)||result.total<0||!Array.isArray(result.rows)
   ||['from','to','channel'].some(name=>result[name]!==filters[name]))throw changed();
  return result;
 }
 const first=await readPage(1),pages=Math.ceil(first.total/first.pageSize);
 for(let page=1;page<=pages;page++){
  const data=page===1?first:await readPage(page);
  if(data.total!==first.total||data.pageSize!==first.pageSize||data.rows.length!==Math.min(first.pageSize,first.total-(page-1)*first.pageSize))throw changed();
  for(const row of data.rows){
   const id=key(row);
   if(!row.orderNumber||!row.channel||!Number.isFinite(Date.parse(row.orderedAt))||seen.has(id)
     ||(filters.channel!=='all'&&row.channel!==filters.channel))throw changed();
   seen.add(id);
   if(activeStatuses.has(row.status))rows.push(row);
  }
 }
 if(pages>1){
  const latest=await readPage(1);
  if(latest.total!==first.total||latest.pageSize!==first.pageSize||JSON.stringify(latest.rows)!==JSON.stringify(first.rows))throw changed();
 }
 if(!rows.length)throw new Error(t('Seçili dönemde belgeye eklenecek satış bulunamadı.'));
 return rows;
}

export function profitabilityDocumentTable(rows,channel){
 const formatted=new Intl.NumberFormat(currentLocale(),{minimumFractionDigits:2,maximumFractionDigits:2});
 const amountLine=(label,cents)=>Number.isSafeInteger(cents)?t(label)+' '+formatted.format(cents/100):'';
 const lines=(main,details)=>details.some(Boolean)?[main,...details].filter(value=>value!==null&&value!==undefined&&value!=='').map(value=>typeof value==='number'?formatted.format(value):value).join('\n'):main;
 return {name:t('Sipariş bazında satış ve kesintiler')+' · '+(channel==='all'?t('Tüm pazaryerleri'):channel),
  head:['Sipariş / Tarih','Pazaryeri','Ürün / SKU / Adet','Satış tutarı (TL)','Alıcının ürün tutarı (TL)','Komisyon','Diğer kesintiler','Alış maliyeti','Kâr/zarar (%)','Hesaba geçecek tutar'].map(name=>t(name)),money:[3,4,5,6,7,9],
  rows:rows.map(row=>{
   let payable=currency(row.afterDeductionsCents);
   if(payable===null)payable=Number.isSafeInteger(row.reportedPayoutCents)?formatted.format(row.reportedPayoutCents/100)+' · '+t('Pazaryerinin bildirdiği hakediş'):t('Ekstre bekleniyor');
   else if(row.status==='statement_stale')payable=payable.toLocaleString(currentLocale(),{minimumFractionDigits:2,maximumFractionDigits:2})+'\n'+t('Son ekstre · kontrol bekleniyor');
   const sale=profitabilitySaleAmount(row);
   const value=currency(sale.cents);
   const pricing=row.orderPricing||{},commission=profitabilityCommissionSummary(row);
   const display=lines(value,[sale.fromOrder?t('Sipariş kaydındaki ürün tutarı'):'',amountLine('Mağazanın karşıladığı indirim',pricing.sellerDiscountCents),amountLine('Pazaryerinin karşıladığı sepet desteği',pricing.platformDiscountCents)]);
   const rate=commission?.rateBasisPoints;
   const commissionValue=lines(currency(commission?.commissionCents),[
    Number.isSafeInteger(rate)?new Intl.NumberFormat(currentLocale(),{style:'percent',maximumFractionDigits:2}).format(rate/10000):'',
    amountLine('Komisyon indirimi',pricing.commissionDiscountCents??row.commissionDiscountCents),
    commission?.commissionCents==null?amountLine('Komisyon (KDV hariç)',row.commissionNetCents):'',
    amountLine('Komisyon sonrası ara tutar',commission?.remainingCents)]);
   const fees=profitabilityFeeEntries(row).map(({label,cents})=>amountLine(label,cents));
   fees.push(amountLine('Ekstredeki kupon hareketi',row.statementCouponCents),amountLine('Ekstredeki kargo kesintisi',row.statementShippingCents));
   const productCosts=(row.products||[]).filter(product=>product.quantity>1||row.products.length>1||product.unitPurchaseCents!==row.purchaseCents)
    .map(product=>Number.isSafeInteger(product.unitPurchaseCents)?(product.sku||product.title||t('Ürün'))+' × '+product.quantity+' · '+t('Birim alış maliyeti')+' '+formatted.format(product.unitPurchaseCents/100):'');
   return [row.orderNumber+'\n'+day(row.orderedAt),row.channel,(row.products||[]).map(product=>([...new Set([product.title,product.sku].filter(Boolean))].join('\n')||'—')+' × '+product.quantity).join('\n'),
    display,currency(pricing.buyerPaidCents),commissionValue,fees.filter(Boolean).join('\n')||null,lines(currency(row.purchaseCents),productCosts),orderMargin(row,currentLocale()),payable];
  })};
}

export async function exportProfitabilityDocument(action,{company,filters,current}){
 const filename=brandedDocumentFilename(company,'Kârlılık Analizi');
 const createFile=async(alive=current)=>{
  const rows=await profitabilityDocumentRows({company,filters,current:alive});
  const [{createPdf},logo]=await Promise.all([import('./report-pdf.js'),companyExportLogo(company)]);
  if(!alive())throw changed();
  const report={company,from:filters.from,to:filters.to,title:t('Kârlılık Analizi'),generatedAt:new Date().toISOString(),footerNote:t('Pazaryeri sipariş ve ekstre kayıtları · Tutarlar TL cinsindendir.')};
  const blob=await createPdf(report,[profitabilityDocumentTable(rows,filters.channel)],[null,logo],{titleInHeader:true});
  if(!alive())throw changed();
  return {blob,filename};
 };
 if(action==='share'){
  openPreparedPdfShare({title:t('Kârlılık Analizi'),description:t('Seçili dönem ve pazaryeri için PDF hazırlanıyor.'),current,createFile});return '';
 }
 if(action==='print'){
  openDocumentPrint({current,createFile:()=>createFile()});return '';
 }
 if(action!=='pdf'||!current())return '';
 const destination=await prepareReportSave(filename);
 if(destination==='cancelled'||!current())return current()?downloadStatus('cancelled'):'';
 const file=await createFile();
 return downloadStatus(await saveReportFile(file.blob,filename,current,globalThis,destination,{browserDownload:true}));
}
