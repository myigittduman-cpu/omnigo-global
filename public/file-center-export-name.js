import {brandedDocumentFilename} from './document-filename.js';
export const fileCenterPdfFilename=(company,date)=>brandedDocumentFilename(company,'Pazaryeri Ürünleri','pdf',date||new Date());
export const fileCenterStockFilename=(data,company,date=new Date())=>brandedDocumentFilename(company,'Stok Listesi','xlsx',date);
