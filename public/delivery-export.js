import {saveReportFile} from './report-download.js';
import {deliveryFilename} from './delivery-filename.js';
import {deliveryLogos} from './delivery-logos.js';
import {deliveryExportCompany} from './company-export-brand.js';
export async function createDeliveryFile(snapshot,type='pdf',context={}){
 const company=deliveryExportCompany(snapshot,context);
 const filename=deliveryFilename(snapshot.cities||snapshot.city,type,{provider:snapshot.provider||'all',...context,company});
 const logos=await deliveryLogos(snapshot,context);
 const document={...snapshot,company};
 const blob=type==='xlsx'?await (await import('./delivery-workbook.js')).createDeliveryWorkbook(document,logos):(await import('./delivery-pdf.js')).createDeliveryPdf(document,logos);
 return {blob,filename};
}
export async function downloadDelivery(snapshot,isCurrent,destination=null,type='pdf',context={},onFileReady){
 const {blob,filename}=await createDeliveryFile(snapshot,type,context);
 if(isCurrent())onFileReady?.({blob,filename});
 return saveReportFile(blob,filename,isCurrent,globalThis,destination,{browserDownload:true});
}
