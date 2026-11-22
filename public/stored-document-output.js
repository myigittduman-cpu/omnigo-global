import {preserveDialogView} from './dialog-view.js';
import {addDialogBack} from './dialog-return.js';
import {openDocumentPrint} from './document-print.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {t} from './i18n.js';

/** Keep the original document form, preview, focus and scroll while an output is open. */
export function openStoredDocumentOutput({action,dialog,current,createFile,title}){
 if(!current()||!dialog.open)return;
 const restore=preserveDialogView(dialog),scope=new AbortController();
 const alive=()=>!scope.signal.aborted&&current();
 const back=()=>{scope.abort();return restore();};
 back.restoresDialog=true;
 try{
  if(action==='print')openDocumentPrint({
   current:alive,formats:['pdf'],directPdf:true,signal:scope.signal,createFile:()=>createFile(alive),
  });
  else openPreparedPdfShare({title:title||t('WhatsApp ile PDF paylaş'),description:t('Seçili belgenin PDF dosyası hazırlanıyor.'),current:alive,createFile});
  addDialogBack(dialog,back,t('Belgeye dön'));
  dialog.addEventListener('omnigo-dialog-dismiss',event=>{event.preventDefault();back();},{signal:scope.signal});
  dialog.addEventListener('close',()=>scope.abort(),{once:true,signal:scope.signal});
 }catch(error){back();throw error;}
}
