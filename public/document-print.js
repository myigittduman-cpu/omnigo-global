import {t} from './i18n.js';
import {toast} from './ui.js';

let activePrint=null;

// Print the existing PDF directly and keep its pages available until preview closes.
export function openDocumentPrint({current,createFile,onClose=()=>{},signal}){
 if(activePrint||signal?.aborted||!current()){
  onClose();
  return;
 }
 if(typeof window.print!=='function'||/OmnigoAndroid/.test(navigator.userAgent)){
  toast(t('Bu cihazda yazdırma ekranı açılamıyor.'));
  onClose();
  return;
 }
 const lifecycle=new AbortController();
 let job;
 const alive=()=>!lifecycle.signal.aborted&&current();
 const finish=()=>{
  if(lifecycle.signal.aborted)return;
  lifecycle.abort();
  job?.dispose();
  activePrint=null;
  onClose();
 };
 activePrint=lifecycle;
 signal?.addEventListener('abort',finish,{once:true,signal:lifecycle.signal});
 window.addEventListener('pagehide',finish,{once:true,signal:lifecycle.signal});

 async function prepare(){
  try{
   const file=await createFile('pdf');
   if(!alive()){finish();return;}
   const {openPdfPrintJob}=await import('./document-print-pdf.js');
   if(!alive()){finish();return;}
   job=await openPdfPrintJob(file.blob,alive);
   if(!alive()){job.dispose();finish();return;}
   if(!await job.render()||!alive()){finish();return;}
   window.addEventListener('afterprint',finish,{once:true,signal:lifecycle.signal});
   window.print();
  }catch(error){
   if(alive())toast(error.message||t('Baskı önizlemesi hazırlanamadı. Yeniden deneyebilirsiniz.'));
   finish();
  }
 }
 void prepare();
}
