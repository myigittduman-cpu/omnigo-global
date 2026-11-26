import {companyExportLogo} from './company-export-brand.js';
import {brandedDocumentFilename} from './document-filename.js';
import {openDocumentPrint} from './document-print.js';
import {t} from './i18n.js';
import {downloadStatus,prepareReportSave,saveReportFile} from './report-download.js';
import {imageData} from './report-export.js';
import {pageExportTables} from './table-page-export-data.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {toast} from './ui.js';

const alignedPages=new Set(['overview','orders','returns','inventory','shipmentInvoices','connections','inbox']);

export async function runTablePageOutput(action,{company,title,view,tables,current}){
 const date=new Date(),format=action==='excel'?'xlsx':'pdf';
 const titleInHeader=alignedPages.has(view);
 const data=pageExportTables(tables,title);
 if(!data.length)throw new Error(t('Dışa aktarılacak kayıt yok.'));
 const filename=brandedDocumentFilename(company,title,format,date);
 const day=date.toLocaleDateString('tr-TR',{timeZone:'Europe/Istanbul'});
 const report={company,title,from:day,to:day,previousFrom:'—',previousTo:'—',generatedAt:date.toISOString(),footerNote:t('Ekranda görünen tablo kayıtları')};
 const createFile=async(alive=current)=>{
  const logo=await companyExportLogo(company).catch(()=>null);
  if(!alive())throw new Error(t('Rapor kapatıldı.'));
  if(format==='xlsx'){
   const {createWorkbook}=await import('./report-xlsx.js');
   return {blob:await createWorkbook(report,data,[logo]),filename};
  }
  const {createPdf}=await import('./report-pdf.js');
  const omnigo=titleInHeader?null:await imageData('/assets/omnigo-logo.png').catch(()=>null);
  if(!alive())throw new Error(t('Rapor kapatıldı.'));
  return {blob:await createPdf(report,data,[omnigo,logo],{titleInHeader}),filename};
 };
 if(action==='print'){
  openDocumentPrint({current,createFile:()=>createFile()});
  return;
 }
 if(action==='share'){
  openPreparedPdfShare({title,description:t('Ekrandaki kayıtların PDF dosyası hazırlanıyor.'),current,createFile});
  return;
 }
 const destination=await prepareReportSave(filename);
 if(!current()||destination==='cancelled')return;
 const file=await createFile();
 const result=await saveReportFile(file.blob,file.filename,current,globalThis,destination,{browserDownload:true});
 if(current())toast(downloadStatus(result));
}
