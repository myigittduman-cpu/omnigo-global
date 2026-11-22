import {brandedDocumentFilename} from './document-filename.js';
import {api} from './api.js';
import {t,text} from './i18n.js';
import {toast} from './ui.js';
import {Workbook} from './vendor/report-xlsx-070.js';
import {configureWorkbook,configureSheet,styleHeader,styleBody,tableHeading,workbookBlob,workbookText} from './workbook-theme.js';
import {downloadStatus,saveReportFile} from './report-download.js';
import {inboxStatuses} from './inbox-status.js';
import {companyExportLogo,exportCompanyName} from './company-export-brand.js';

export async function downloadInboxWorkbook(params,current,company){
 const companyId=Number(params.get('company'));
 if(!Number.isSafeInteger(companyId)||companyId<1||Number(company?.id)!==companyId)throw new Error(t('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
 const items=[];
 const seen=new Set();
 for(let page=0;;page++){
  if(!current())return;
  params.set('page',String(page));
  const result=await api('/inbox/notifications?'+params);
  if(!result.available)throw new Error(t('Soru listesi şu anda kullanılamıyor.'));
  for(const row of result.items){
   if(Number(row.companyId)!==companyId)throw new Error(t('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
   if(seen.has(row.threadId))continue;
   seen.add(row.threadId);items.push(row);
  }
  if(!result.hasMore)break;
  if(page>=999)throw new Error(t('İndirme için listeyi filtreleyin.'));
 }
 if(!current())return;
 const file=await createInboxWorkbook(items,company);
 const result=await saveReportFile(file.blob,file.filename,current);
 if(current()&&result!=='stale')toast(downloadStatus(result));
}

export async function createInboxWorkbook(items,company,createdAt=new Date()){
 const brand=exportCompanyName(company),title=t('Pazaryeri Soruları');
 const book=new Workbook(),sheet=book.addWorksheet(title);
 configureWorkbook(book,createdAt,brand);
 configureSheet(sheet,{frozenColumns:0,company:brand});
 const widths=[21,48,23,24,24,76,23,22,23];
 widths.forEach((value,index)=>{sheet.getColumn(index+1).width=value;});
 tableHeading(sheet,{title,context:text`${items.length} soru`,note:'',columns:widths.length,brand,logo:await companyExportLogo(company)});
 styleHeader(sheet.addRow(['Pazaryeri','Ürün adı','SKU','Ürün kodu','Sipariş numarası','Soru','Soru tarihi','Durum','Son yanıt tarihi'].map(label=>t(label))),widths.length);
 items.forEach((row,index)=>{
  const values=[row.provider,row.title,row.sku,row.productCode,row.orderNumber,row.question??row.preview,row.created,inboxStatuses[row.status],row.expiresAt].map(workbookText);
  styleBody(sheet.addRow(values),widths,{index,texts:[1,2,3,4,5,6,7,8,9]});
 });
 return {blob:await workbookBlob(book),filename:brandedDocumentFilename(company,'Pazaryeri Soruları','xlsx',createdAt)};
}
