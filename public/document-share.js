import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {reportFile,shareReportFile} from './report-file-action.js';
import {shareFilename} from './share-filename.js';
export const whatsappIcon=i18nHtml('<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.6a8 8 0 0 1-11.8 7L4 20l1.3-4.1A8 8 0 1 1 20 11.6Z"/><path d="M8.7 7.8c-.6.3-.9 1-.7 1.7.6 2.5 2.4 4.4 4.9 5.1.8.2 1.5-.1 1.9-.8l.5-1-2-1-.8.8a6.2 6.2 0 0 1-2.7-2.7l.7-.8-1-1.8Z"/></svg>');
export const documentShareControls=()=>i18nHtml`<button type="button" class="btn" data-prepare-share="pdf" disabled>PDF hazırlanıyor…</button><button type="button" class="btn" data-prepare-share="xlsx" disabled>Excel hazırlanıyor…</button>
 <p data-file-share-message role="status" aria-live="polite"></p>`;
export function bindDocumentShare(container,{current,createFile,sharePdfOnOpen=false,onFileReady},environment=globalThis){
 const buttons=[...container.querySelectorAll('[data-prepare-share]')],files=new Map(),preparing=new Set();
 let busy=false,chosen=false;
 const feedback=text=>{if(current())container.querySelector('[data-file-share-message]').textContent=text;};
 const refresh=()=>{if(current())for(const button of buttons)button.disabled=busy||preparing.has(button.dataset.prepareShare);};
 async function share(type){
  if(busy||!current())return;
  chosen=true;busy=true;refresh();feedback(i18nT('Paylaşım açılıyor…'));
  try{
   const result=await shareReportFile(files.get(type),current,environment);
   feedback(result==='cancelled'?i18nT('Paylaşım iptal edildi.'):result==='stale'?'':i18nT('Dosya paylaşım ekranına aktarıldı. Alıcıyı seçip gönderebilirsiniz.'));
  }catch(error){feedback(error.message);}
  finally{busy=false;refresh();}
 }
 async function prepare(button){
  const type=button.dataset.prepareShare;
  if(preparing.has(type))return;
  preparing.add(type);refresh();
  try{
   const result=await createFile(type);if(!current())return;
   const file=reportFile(result.blob,shareFilename(result.filename),environment);
   files.set(type,file);onFileReady?.({blob:file,filename:file.name});
   button.textContent=i18nT(type==='xlsx'?'Excel dökümünü hazırla':sharePdfOnOpen?'WhatsApp ile paylaş':'PDF oluştur');
   if(type==='pdf'&&sharePdfOnOpen&&!chosen){
    if(typeof environment.__OMNIGO_SHARE_REPORT__==='function'||environment.navigator?.userActivation?.isActive===true)await share(type);
    else feedback(i18nT('PDF hazır. Konuşma seçimini açmak için paylaş düğmesine dokunun.'));
   }
  }catch(error){if(current()){button.textContent=i18nT('Yeniden dene');feedback(error.message);}}
  finally{preparing.delete(type);refresh();}
 }
 buttons.forEach(button=>button.onclick=async()=>{
  if(busy||!current()||preparing.has(button.dataset.prepareShare))return;
  const type=button.dataset.prepareShare;
  if(files.has(type))return share(type);
  if(type!=='pdf')chosen=true;
  await prepare(button);
 });
 const ready=Promise.allSettled(buttons.map(prepare));return {ready};
}
