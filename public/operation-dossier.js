import {brandedDocumentFilename} from './document-filename.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {api,upload} from './api.js';
import {openDialog} from './ui.js';
import {t} from './i18n.js';
import {loadingState,contentError} from './content-state.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {enhanceDateInputs} from './date-inputs.js';
import {prepareShipmentDocument,shipmentDocumentFile} from './shipment-document-files.js';
import {shareFilename} from './share-filename.js';
import {openStoredDocumentOutput} from './stored-document-output.js';
import {renderShipmentDocument} from './shipment-document-preview.js';
import {prepareReportSave,saveReportFile,downloadStatus} from './report-download.js';
import {dossierView} from './operation-dossier-view.js';

const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(24)),v=>v.toString(16).padStart(2,'0')).join('');
export function dossierQuery(company,source){return new URLSearchParams({company,...Object.fromEntries(Object.entries(source).filter(([key])=>['kind','id','provider','ref'].includes(key)))}).toString();}
export async function openOperationDossier(state,source){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state),query=dossierQuery(token.company,source),endpoint='/operations/dossier?'+query,events=new AbortController();let busy=false,disposePreview=()=>{};
 const dialog=openDialog(t('Teslimat ve iade dosyası'),loadingState('Sipariş dosyası açılıyor…'),{className:'operation-dossier-dialog'}),body=dialog.querySelector('.dialog-body');
 const current=()=>dialog.open&&dialog.contains(body)&&sameWorkspace(state,token)&&!events.signal.aborted;
 dialog.addEventListener('omnigo-dialog-dismiss',event=>{if(busy)event.preventDefault();},{signal:events.signal});
 dialog.addEventListener('close',()=>{disposePreview();events.abort();},{once:true});
 function paint(data){
  if(!current())return;disposePreview();body.innerHTML=dossierView(data);const form=body.querySelector('form');enhanceDateInputs(form);let nonce=requestId();
  form.addEventListener('input',()=>nonce=requestId());form.addEventListener('change',()=>nonce=requestId());
  form.onsubmit=async event=>{event.preventDefault();if(busy||!current()||!form.reportValidity())return false;busy=true;const error=form.querySelector('[role=alert]'),submit=form.querySelector('[type=submit]');error.textContent='';submit.disabled=true;
   try{const fields=Object.fromEntries(new FormData(form));delete fields.evidence;const uploadBody=new FormData();uploadBody.set('data',JSON.stringify({fields,version:data.version,requestId:nonce}));const file=form.elements.evidence.files[0];if(file){const prepared=await prepareShipmentDocument(file);uploadBody.set('file',prepared,file.name);}if(!current())return false;
    const saved=await upload(endpoint,uploadBody);if(current()){form.reset();paint(saved);}return true;
   }catch(e){if(current())error.textContent=e.message;return false;}finally{busy=false;if(current())submit.disabled=false;}
  };
  body.onclick=async event=>{
   const button=event.target.closest('button');
   if(!button||busy||!current())return;
   const {evidenceShare,evidencePrint,evidencePdf,evidenceDownload,evidencePreview}=button.dataset;
   const key=evidenceShare||evidencePrint||evidencePdf||evidenceDownload||evidencePreview;
   const file=data.files.find(item=>item.key===key);
   if(!file)return;
   const feedback=body.querySelector('[data-evidence-status]');
   const shopCurrent=()=>state.user?.role==='admin'&&sameWorkspace(state,token)&&!events.signal.aborted;
   const read=()=>api('/operations/file?'+query+'&file='+encodeURIComponent(key),'GET',undefined,{signal:events.signal});
   const base=shareFilename(file.name.replace(/\.[^.]*$/,'')).slice(0,50)||'Belge';
   const company=workspaceExportCompany(state,token.company);
   const pdfName=brandedDocumentFilename(company,base,'pdf');
   if(evidenceShare||evidencePrint){
    openStoredDocumentOutput({action:evidenceShare?'share':'print',dialog,current:shopCurrent,title:t('WhatsApp ile paylaş'),
     createFile:async alive=>{
      const document=await read();
      if(alive())return {blob:await shipmentDocumentFile(document,'pdf'),filename:pdfName};
     }});
    return;
   }
   busy=true;button.disabled=true;
   try{
    if(evidencePreview){
     const document=await read();
     if(!current())return;
     const preview=body.querySelector('.operation-preview');preview.hidden=false;disposePreview();
     disposePreview=await renderShipmentDocument(preview,document,{current,signal:events.signal});feedback.textContent='';
    }else{
     const filename=evidencePdf?pdfName:brandedDocumentFilename(company,base,globalThis.__OMNIGO_DESKTOP__?'jpeg':'jpg');
     const destination=await prepareReportSave(filename);
     if(!current())return;
     if(destination==='cancelled'){feedback.textContent=downloadStatus(destination);return;}
     const document=await read();
     if(!current())return;
     const blob=await shipmentDocumentFile(document,evidencePdf?'pdf':'jpeg');
     if(current())feedback.textContent=downloadStatus(await saveReportFile(blob,filename,current,globalThis,destination,{browserDownload:true}));
    }
   }catch(error){if(current())feedback.textContent=error.message;}
   finally{busy=false;if(current())button.disabled=false;}
  };
 }
 try{const data=await api(endpoint,'GET',undefined,{signal:events.signal});paint(data);}catch(e){if(current())body.innerHTML=contentError(e.message);}
 return dialog;
}
