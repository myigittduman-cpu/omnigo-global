import {t,html,text} from './i18n.js';
import {openDialog} from './ui.js';
import {prepareReportSave,saveReportFile,downloadStatus} from './report-download.js';

/** Every printable table supplies the same file factory used by its existing export. */
export function openDocumentPrint({current,createFile,formats=['pdf','xlsx'],onClose=()=>{},directPdf=false,autoPrint=false,allowSave=true,signal}){
 let busy=false;
 const choices=directPdf?'':html`
  <p>Hangi biçimde yazdırmak istersiniz?</p>
  <div class="shipment-document-actions">
   ${formats.map(type=>html`<button type="button" class="btn" data-print-format="${type}">${type==='pdf'?'PDF':'Excel'}</button>`).join('')}
  </div>`;
 const dialog=openDialog(t('Yazdır'),html`${choices}<p data-print-status role="status"></p><div class="shipment-document-actions" data-print-actions></div>`,
  {className:'shipment-document-dialog',draftGuard:{attach(){},dispose(){},async canLeave(){return !busy;}}});
 const body=dialog.querySelector('.dialog-body');
 const actions=body.querySelector('[data-print-actions]'),feedback=body.querySelector('[data-print-status]');
 const alive=()=>!signal?.aborted&&dialog.open&&body.isConnected&&current();
 let dispose=()=>{},file=null,job=null,batch=0,batchSize=0;
 signal?.addEventListener('abort',()=>dispose(),{once:true});
 dialog.addEventListener('close',()=>{dispose();onClose();},{once:true,signal});
 const status=text=>{if(alive())feedback.textContent=text;};
 const lock=value=>{busy=value;if(alive())dialog.querySelectorAll('button').forEach(b=>b.disabled=value);};
 const printUnavailable=()=>t(allowSave?'PDF hazır. Dosyayı kaydedip cihazınızın belge uygulamasından yazdırabilirsiniz.':'Bu cihazda yazdırma ekranı açılamıyor.');
 function printNow(){
  try{window.print();if(!job||job.pages<=batchSize)status(t('Yazdırma penceresinde yazıcınızı ve sayfaları seçin.'));}
  catch{status(t('Yazdırma ekranı açılamadı. Yeniden deneyebilirsiniz.'));}
 }
 const retry=()=>html`<button class="btn" type="button" data-print-format="pdf">Yeniden dene</button>`;
 function showPrintActions(canPrint,type){
  const multiple=canPrint&&job.pages>batchSize;
  actions.innerHTML=html`
   ${multiple&&batch>0?html`<button class="btn" type="button" data-print-batch="${batch-1}">Önceki bölüm</button>`:''}
   ${canPrint?html('<button class="btn primary" type="button" data-print-now>Yazdırma ekranını aç</button>'):''}
   ${multiple&&(batch+1)*batchSize<job.pages?html`<button class="btn" type="button" data-print-batch="${batch+1}">Sonraki bölüm</button>`:''}
   ${allowSave?html`<button class="btn" type="button" data-print-save>${type==='xlsx'?'Excel dosyasını kaydet':'PDF dosyasını kaydet'}</button>`:''}`;
  status(multiple?text`Yazdırılacak sayfalar: ${batch*batchSize+1}–${Math.min((batch+1)*batchSize,job.pages)} / ${job.pages}`
   :type==='xlsx'?t('Excel dosyası mevcut sayfa ve baskı düzeniyle hazır. Kaydettikten sonra Excel’de açıp Yazdır seçeneğini kullanın.')
   :canPrint?t('Yazıcı, kağıt boyutu ve sayfa aralığını yazdırma ekranında seçebilirsiniz.'):printUnavailable());
 }
 const previewFailed=error=>{
  console.warn('PDF print preview unavailable',error);
  dispose();job=null;
  if(alive()){
   actions.innerHTML=retry();
   status(t('Baskı önizlemesi hazırlanamadı. Yeniden deneyebilirsiniz.'));
  }
 };
 async function prepareBatch(next){
  lock(true);status(t('Yazdırılacak sayfalar hazırlanıyor…'));
  try{
   if(await job.render(next)&&alive()){batch=next;showPrintActions(true,'pdf');}
  }catch(error){previewFailed(error);}
  finally{lock(false);}
 }
 async function prepare(type){
  lock(true);dispose();file=null;job=null;batch=0;actions.replaceChildren();status(t('Yazdırılacak dosya hazırlanıyor…'));
  try{
   file=await createFile(type);
   if(!alive())return;
   let canPrint=false;
   if(type==='pdf'&&typeof window.print==='function'&&(directPdf||!window.__OMNIGO_DESKTOP__)&&!/OmnigoAndroid/.test(navigator.userAgent)){
    try{
     const {openPdfPrintJob,pdfPrintBatchSize}=await import('./document-print-pdf.js');
     job=await openPdfPrintJob(file.blob,alive);dispose=job.dispose;batchSize=pdfPrintBatchSize;
     canPrint=await job.render(batch);
    }catch(error){if(!allowSave){previewFailed(error);return;}console.warn('PDF print preview unavailable',error);dispose();job=null;}
   }
   if(!alive()){dispose();return;}
   showPrintActions(canPrint,type);
   if(autoPrint&&canPrint)printNow();
  }catch(error){
   status(error.message);
   if(directPdf&&alive())actions.innerHTML=retry();
  }finally{lock(false);}
 }
 body.addEventListener('click',async event=>{
  const button=event.target.closest('[data-print-format],[data-print-save],[data-print-now],[data-print-batch]');
  if(!button||busy||!alive())return;
  if(button.hasAttribute('data-print-now')){printNow();return;}
  if(button.hasAttribute('data-print-batch')){await prepareBatch(Number(button.dataset.printBatch));return;}
  if(button.hasAttribute('data-print-save')){
   lock(true);
   try{
    const destination=await prepareReportSave(file.filename);
    if(alive())status(downloadStatus(await saveReportFile(file.blob,file.filename,alive,globalThis,destination,{browserDownload:directPdf})));
   }catch(error){status(error.message);}
   finally{lock(false);}
   return;
  }
  await prepare(button.dataset.printFormat);
 });
 if(directPdf)void prepare('pdf');
 return dialog;
}
