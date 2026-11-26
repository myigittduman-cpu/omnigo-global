import {brandedDocumentFilename} from './document-filename.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {companyExportLogo} from './company-export-brand.js';
import {reportTables} from './report-export-data.js';
import {prepareReportSave,saveReportFile} from './report-download.js';
export async function imageData(url){
 const parsed=new URL(url,location.origin);if(parsed.origin!==location.origin)throw new Error(i18nT('Logo kaynağı geçersiz.'));
 const response=await fetch(parsed,{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(15000)});
 if(!response.ok)throw new Error(i18nT('Rapor logosu yüklenemedi. Lütfen yeniden deneyin.'));
 const blob=await response.blob();if(!blob.type.startsWith('image/')||blob.size>3000000)throw new Error(i18nT('Logo dosyası uygun değil.'));
 const bitmap=await createImageBitmap(blob),canvas=document.createElement('canvas');
 const scale=Math.min(1,600/Math.max(bitmap.width,bitmap.height));canvas.width=Math.round(bitmap.width*scale);canvas.height=Math.round(bitmap.height*scale);
 canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();
 return {data:canvas.toDataURL('image/png'),width:canvas.width,height:canvas.height};
}
export async function createReportFile(report,type){
 const logo=await companyExportLogo(report.company);
 const tables=reportTables(report);let blob;
 if(type==='pdf'){const {createPdf}=await import('./report-pdf.js');blob=await createPdf(report,tables,[null,logo],{titleInHeader:true});}
 else if(type==='xlsx'){const {createWorkbook}=await import('./report-xlsx.js');blob=await createWorkbook(report,tables,logo?[logo]:[]);}
 else throw new Error(i18nT('Rapor biçimi desteklenmiyor.'));
 return {blob,filename:reportFilename(report,type)};
}

function reportFilename(report,type){
 return brandedDocumentFilename(report.company,'Analiz ve Raporlar',type);
}
export async function downloadReport(report,type,isCurrent=()=>true,onFileReady){
 if(!isCurrent())return 'stale';
 const destination=await prepareReportSave(reportFilename(report,type));
 if(!isCurrent())return 'stale';
 if(destination==='cancelled')return 'cancelled';
 const file=await createReportFile(report,type);
 if(isCurrent())onFileReady?.(file);
 return saveReportFile(file.blob,file.filename,isCurrent,globalThis,destination,{browserDownload:true});
}
