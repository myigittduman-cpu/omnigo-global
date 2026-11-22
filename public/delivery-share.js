import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,openDialog} from './ui.js';
import {documentShareControls,bindDocumentShare} from './document-share.js';
import {exportCompanyName} from './company-export-brand.js';

export function openDeliveryShare({city,count,current,loadDocument,onClose,onFileReady,filenameContext={}}){
 const dialog=openDialog(i18nText`${exportCompanyName(filenameContext.company)} · Teslimat evraklarını paylaş`,i18nHtml`<div class="shipment-document-options" data-delivery-share-content><p>${esc(city==='all'?i18nT('Tüm Şehirler'):city)} · ${count} sipariş</p>${documentShareControls()}</div>`);
 const content=dialog.querySelector('[data-delivery-share-content]'),alive=()=>current()&&dialog.open&&content.isConnected;
 dialog.addEventListener('close',onClose,{once:true});
 let snapshotPromise;
 bindDocumentShare(content,{current:alive,sharePdfOnOpen:true,createFile:async type=>{
  const snapshot=await(snapshotPromise??=loadDocument().catch(error=>{snapshotPromise=null;throw error;}));if(!alive())throw new Error(i18nT('Sayfa değişti. Paylaşımı yeniden açın.'));
  const file=await (await import('./delivery-export.js')).createDeliveryFile(snapshot,type,filenameContext);
  if(alive())onFileReady?.(file);
  return file;
 }});
}
