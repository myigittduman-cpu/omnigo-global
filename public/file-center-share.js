import {t,text} from './i18n.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {exportCompanyName} from './company-export-brand.js';

export function shareFileCenterProducts(data,company,current,onFileReady){
 return openPreparedPdfShare({
  title:text`${exportCompanyName(company)} · Pazaryeri ürünlerini paylaş`,
  description:text`${data.items.length} ürün · PDF`,current,onFileReady,
  createFile:async alive=>{
   const {createFileCenterPdf}=await import('./file-center-pdf.js');
   if(!alive())throw new Error(t('Paylaşım kapatıldı.'));
   return createFileCenterPdf(data,company);
  }
 });
}
