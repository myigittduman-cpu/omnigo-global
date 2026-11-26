import {cityProductsDocument} from './city-products-document.js';
import {cityProductsLogo} from './city-products-logo.js';
import {openDocumentPrint} from './document-print.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {downloadStatus,saveReportFile} from './report-download.js';
import {t} from './i18n.js';

export async function exportCityProducts({format,state,city,orders,stillCurrent}){
 if(!stillCurrent())return '';
 if(!['pdf','print','excel','whatsapp'].includes(format))throw new Error(t('Dosya biçimi desteklenmiyor.'));
 const companyId=Number(state.company),model=cityProductsDocument(state,city,orders);
 const current=()=>stillCurrent()&&Number(state.company)===companyId;
 async function createFile(type){
  if(!current())throw new Error(t('Mağaza veya seçim değişti. Çıktıyı yeniden açın.'));
  const logo=await cityProductsLogo(model.company);
  if(!current())throw new Error(t('Mağaza veya seçim değişti. Çıktıyı yeniden açın.'));
  const blob=type==='xlsx'
   ?await (await import('./city-products-workbook.js')).cityProductsWorkbook(model,logo)
   :(await import('./city-products-pdf.js')).cityProductsPdf(model,logo);
  if(!current())throw new Error(t('Mağaza veya seçim değişti. Çıktıyı yeniden açın.'));
  return {blob,filename:type==='xlsx'?model.excelName:model.pdfName};
 }
 if(format==='whatsapp'){
  openPreparedPdfShare({title:t('Şehir Dışı Ürünler · WhatsApp ile PDF paylaş'),description:t('Seçili siparişlerin PDF belgesi hazırlanıyor.'),
   current,createFile:()=>createFile('pdf')});
  return '';
 }
 if(format==='print'){
  openDocumentPrint({current,createFile});return '';
 }
 const file=await createFile(format==='excel'?'xlsx':'pdf');
 return downloadStatus(await saveReportFile(file.blob,file.filename,current,globalThis,null,{browserDownload:true}));
}
