import {brandedDocumentFilename} from './document-filename.js';
import {companyExportLogo} from './company-export-brand.js';
import {t} from './i18n.js';
import {upcomingMarketplaceEntries,upcomingPaymentDocumentModel,upcomingPaymentStatus,upcomingPaymentTitle} from './upcoming-payment-model.js';
import {createWorkbook} from './report-xlsx.js';

export function upcomingPaymentExportTable(model){
 const entries=upcomingPaymentDocumentModel(model).rows.flatMap(market=>
  upcomingMarketplaceEntries(market).map(item=>({...item,channel:market.name})));
 return {
  name:t('Hakedişlerim'),head:[t('Pazaryeri'),t('Planlanan tarih'),t('Tutar (TL)'),t('Durum')],
  rows:entries.map(item=>[item.channel,item.date,item.amountCents/100,
   item.paymentApproval?.at?t('Ödendi')+' ✓':upcomingPaymentStatus(item)]),
  money:[2],receivedRows:entries.flatMap((item,index)=>item.paymentApproval?.at?[index]:[]),
 };
}

export async function createUpcomingPaymentWorkbookFile(model,company,{logo,downloadedAt=new Date()}={}){
 const table=upcomingPaymentExportTable(model);
 if(!table.rows.length)throw new Error(t('Yaklaşan hakediş kaydı bulunmuyor.'));
 const report={company,title:upcomingPaymentTitle(company),from:model.period?.start||model.dates[0],
  to:model.period?.end||model.dates.at(-1),previousFrom:'—',previousTo:'—',generatedAt:downloadedAt.toISOString()};
 const companyLogo=logo===undefined?await companyExportLogo(company).catch(()=>null):logo;
 return {blob:await createWorkbook(report,[table],[companyLogo]),
  filename:brandedDocumentFilename(company,'Ödeme Tablosu','xlsx',downloadedAt)};
}
