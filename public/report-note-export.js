import {brandedDocumentFilename} from './document-filename.js';
import {imageData} from './report-export.js';
import {companyExportLogo} from './company-export-brand.js';
import {t} from './i18n.js';
export const noteFilename=(note,company,type)=>brandedDocumentFilename(company,'Mağaza Raporu',type);
export async function createNoteFile(note,company,type){
 const report={company,title:t('Çalışma raporu'),from:note.month,to:note.month,previousFrom:'—',previousTo:'—',generatedAt:new Date().toISOString()};
 const tables=[{name:t('Rapor'),head:[t('Başlık'),t('Açıklama')],rows:note.content.split(/\n\s*\n/).map((text,index)=>[index?'':note.title,text]),money:[]}];
 const logo=await companyExportLogo(company);let blob;
 if(type==='pdf'){const {createPdf}=await import('./report-pdf.js');blob=await createPdf(report,tables,[await imageData('/assets/omnigo-logo.png'),...(logo?[logo]:[])]);}
 else{const {createWorkbook}=await import('./report-xlsx.js');blob=await createWorkbook(report,tables,logo?[logo]:[]);}
 return {blob,filename:noteFilename(note,company,type)};
}
