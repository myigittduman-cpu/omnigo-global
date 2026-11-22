import {t,html} from './i18n.js';
import {openDialog} from './ui.js';
import {prepareReportSave,saveReportFile,downloadStatus} from './report-download.js';

/** Every printable table supplies the same file factory used by its existing export. */
export function openDocumentPrint({current,createFile,formats=['pdf','xlsx'],onClose=()=>{},directPdf=false,signal}){
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
 let dispose=()=>{},file=null;
 signal?.addEventListener('abort',()=>dispose(),{once:true});
 dialog.addEventListener('close',()=>{dispose();onClose();},{once:true,signal});
 const status=text=>{if(alive())feedback.textContent=text;};
 const lock=value=>{busy=value;if(alive())dialog.querySelectorAll('button').forEach(b=>b.disabled=value);};
 async function prepare(type){
  lock(true);dispose();file=null;actions.replaceChildren();status(t('Yazdırılacak dosya hazırlanıyor…'));
  try{
   file=await createFile(type);
   if(!alive())return;
   let canPrint=false;
   if(type==='pdf'&&typeof window.print==='function'&&(directPdf||!window.__OMNIGO_DESKTOP__)&&!/OmnigoAndroid/.test(navigator.userAgent)){
    try{
     const {preparePdfPrint}=await import('./document-print-pdf.js');
     dispose=await preparePdfPrint(file.blob,alive);canPrint=true;
    }catch(error){console.warn('PDF print preview unavailable',error);}
   }
   if(!alive()){dispose();return;}
   actions.innerHTML=html`
    ${canPrint?html('<button class="btn primary" type="button" data-print-now>Yazdırma ekranını aç</button>'):''}
    <button class="btn" type="button" data-print-save>${type==='xlsx'?'Excel dosyasını kaydet':'PDF dosyasını kaydet'}</button>`;
   status(type==='xlsx'?t('Excel dosyası mevcut sayfa ve baskı düzeniyle hazır. Kaydettikten sonra Excel’de açıp Yazdır seçeneğini kullanın.'):canPrint?t('PDF hazır. Yazıcı, kağıt boyutu ve sayfa aralığını yazdırma ekranında seçebilirsiniz.'):t('PDF hazır. Dosyayı kaydedip cihazınızın belge uygulamasından yazdırabilirsiniz.'));
  }catch(error){
   status(error.message);
   if(directPdf&&alive())actions.innerHTML=html`<button class="btn" type="button" data-print-format="pdf">Yeniden dene</button>`;
  }finally{lock(false);}
 }
 body.addEventListener('click',async event=>{
  const button=event.target.closest('[data-print-format],[data-print-save],[data-print-now]');
  if(!button||busy||!alive())return;
  if(button.hasAttribute('data-print-now')){
   try{window.print();status(t('Yazdırma penceresinde yazıcınızı ve sayfaları seçin.'));}
   catch{status(t('Yazdırma ekranı açılamadı. Dosyayı kaydedip cihazınızın belge uygulamasından yazdırabilirsiniz.'));}
   return;
  }
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
