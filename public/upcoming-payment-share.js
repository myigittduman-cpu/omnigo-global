import {html,t} from './i18n.js';
import {bindDocumentShare} from './document-share.js';
import {esc,openDialog} from './ui.js';

export function openUpcomingPaymentShare({model,company,current,onFileReady}){
 if(!model.dates.length||!current())return null;
 return openPreparedPdfShare({title:t('Hakediş paylaşımı'),description:t('Seçili ödemelerin PDF belgesi hazırlanıyor.'),current,onFileReady,createFile:async alive=>{
  const {createUpcomingPaymentFile}=await import('./upcoming-payment-export.js');
  if(alive())return createUpcomingPaymentFile(model,company,{fitToContent:true});
 }});
}

export function openPreparedPdfShare({title,description,current,onFileReady,createFile}){
 if(!current())return null;
 const dialog=openDialog(title,html`<div class="shipment-document-options" data-upcoming-pdf-share>
  <p data-upcoming-share-help>${esc(description)}</p>
  <div data-upcoming-share-actions>
   <button type="button" class="btn primary" data-prepare-share="pdf" disabled>PDF hazırlanıyor…</button>
  </div>
  <p data-file-share-message role="status" aria-live="polite"></p>
 </div>`,{className:'upcoming-payments-share-dialog'});
 const content=dialog.querySelector('[data-upcoming-pdf-share]');
 const alive=()=>current()&&dialog.open&&content.isConnected;
 bindDocumentShare(content,{current:alive,sharePdfOnOpen:true,createFile:()=>createFile(alive),onFileReady:file=>{content.querySelector("[data-upcoming-share-help]").hidden=true;onFileReady?.(file);}});
 return dialog;
}
