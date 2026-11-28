import {brandedDocumentFilename} from './document-filename.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {documentOutputActions} from './document-output-actions.js';
import {openStoredDocumentOutput} from './stored-document-output.js';
import {api,upload} from './api.js';
import {openDialog,esc} from './ui.js';
import {html,t} from './i18n.js';
import {customerCan} from './customer-access.js';
import {usesCustomerWorkspaceStandard} from './customer-workspace-standard.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {prepareShipmentDocument,shipmentDocumentFile} from './shipment-document-files.js';
import {renderShipmentDocument} from './shipment-document-preview.js';
import {prepareReportSave,saveReportFile,downloadStatus} from './report-download.js';
const nonce=()=>Array.from(crypto.getRandomValues(new Uint8Array(24)),v=>v.toString(16).padStart(2,'0')).join('');
export async function openOrderDocuments(state,id){
 if(!customerCan(state,'orders','documents'))return;
 if(!document.querySelector('link[href="/shipment-document-preview.css"]')){const style=document.createElement('link');style.rel='stylesheet';style.href='/shipment-document-preview.css';document.head.append(style);}
 const token=workspaceToken(state),endpoint=kind=>`/orders/${id}/documents${kind?'/'+kind:''}?company=${token.company}`;
 const dialog=openDialog(t('Fatura ve teslimat evrakı'),html('<p class="form-help">Bu siparişin belgeleri yetkili yönetici ve müşteri hesaplarında ortak görüntülenir. Değiştir, mevcut dosyanın yerine yeni dosya yükler.</p><div data-order-document-list></div><p data-document-feedback role="status"></p><section class="shipment-document-preview" hidden></section>'),{className:'shipment-document-dialog'});
 const events=new AbortController(),{signal}=events;let data=null,busy=false,timer,dispose=()=>{};
 const list=dialog.querySelector('[data-order-document-list]');
 const session=()=>dialog.open&&sameWorkspace(state,token)&&customerCan(state,'orders','documents')&&!signal.aborted;
 const current=()=>session()&&list.isConnected;
 const feedback=value=>{if(current())dialog.querySelector('[data-document-feedback]').textContent=value;};
 const can=action=>customerCan(state,'orders',action);
 const paint=()=>{
  if(!current()||!data)return;
  list.innerHTML=html`<div class="order-document-grid">${[['invoice','Fatura'],['delivery','Teslimat evrağı']].map(([kind,label])=>{
   const item=data.items[kind],canUpload=can('upload')&&(kind==='invoice'||!usesCustomerWorkspaceStandard(state))&&(!item.present||can('replaceDocuments'));
   return html`<section class="panel order-document-card"><h3>${t(label)}</h3><p>${item.present?t('Belge yüklendi.'):t('Henüz belge eklenmedi.')}</p>${item.present?html`<div class="shipment-document-actions">
    <button type="button" class="btn" data-order-doc-view="${kind}">Görüntüle</button>
    ${documentOutputActions({pdf:{attr:'data-order-doc-pdf',value:kind},share:{attr:'data-order-doc-share',value:kind},print:{attr:'data-order-doc-print',value:kind}})}
    ${item.mime==='image/jpeg'?html`<button type="button" class="btn" data-order-doc-download="${kind}">Görseli indir</button>`:''}
    ${can('deleteDocuments')?html`<button type="button" class="btn" data-order-doc-delete="${kind}">Sil</button>`:''}</div>`:''}${canUpload?html`<form class="form-grid" data-order-doc-upload="${kind}"><label>${item.present?t('Değiştir — yeni belge seç'):t('Dosya Ekle')}<input type="file" name="file" accept="application/pdf,image/jpeg,image/png,image/webp" required></label><small>PDF en fazla 4 MB; JPEG, PNG veya WebP en fazla 12 MB. Görseller yüklenirken uygun boyuta getirilir.</small><button type="submit" class="btn primary">${item.present?t('Değiştir ve kaydet'):t('Yükle ve kaydet')}</button></form>`:''}</section>`;
  }).join('')}</div>`;
  dialog.querySelectorAll('[data-order-doc-upload]').forEach(form=>form.onsubmit=submitDocument);
 };
 const lock=value=>{busy=value;if(current())dialog.querySelectorAll('input,button').forEach(el=>el.disabled=value);};
 async function refresh(){
  if(!current()||busy||[...dialog.querySelectorAll('[type=file]')].some(el=>el.files?.length))return;
  try{const fresh=await api(endpoint());if(!current()||busy||[...dialog.querySelectorAll('[type=file]')].some(el=>el.files?.length))return;if(JSON.stringify(fresh.items)!==JSON.stringify(data?.items)){dispose();dialog.querySelector('.shipment-document-preview').hidden=true;data=fresh;paint();}}
  catch(error){feedback(error.message);}
 }
 async function submitDocument(event){
  const form=this?.matches?.('[data-order-doc-upload]')?this:event.target?.closest('[data-order-doc-upload]');if(!form)return false;event.preventDefault();if(busy||!current())return false;
  const kind=form.dataset.orderDocUpload;if(kind!=='invoice'&&usesCustomerWorkspaceStandard(state))return false;lock(true);feedback(t('Dosya hazırlanıyor…'));
  try{const blob=await prepareShipmentDocument(form.elements.file.files[0]);if(!current())return;const body=new FormData();body.set('file',blob,blob.type==='application/pdf'?'belge.pdf':'belge.jpeg');body.set('version',data.items[kind].version);body.set('requestId',nonce());await upload(endpoint(kind),body);if(current()){form.reset();feedback(t('Belge kaydedildi.'));return true;}}
  catch(error){feedback(error.message);return false;}finally{lock(false);await refresh();}
 }
 dialog.addEventListener('click',async event=>{
  const output=event.target.closest('[data-order-doc-print],[data-order-doc-share]');
  if(output&&!busy&&current()){
   const kind=output.dataset.orderDocPrint||output.dataset.orderDocShare;
   if(!data?.items[kind]?.present)return;
   openStoredDocumentOutput({action:output.hasAttribute('data-order-doc-print')?'print':'share',dialog,current:session,
    createFile:async()=>({blob:await shipmentDocumentFile(await api(endpoint(kind)),'pdf'),filename:brandedDocumentFilename(workspaceExportCompany(state,token.company),kind==='invoice'?'Fatura':'Teslimat Evrakı','pdf')})});
   return;
  }
  const button=event.target.closest('[data-order-doc-view],[data-order-doc-download],[data-order-doc-pdf],[data-order-doc-delete]');if(!button||busy||!current())return;
  const kind=button.dataset.orderDocView||button.dataset.orderDocPdf||button.dataset.orderDocDownload||button.dataset.orderDocDelete;
  if(!data?.items[kind]?.present)return;
  lock(true);
  try{
   if(button.hasAttribute('data-order-doc-delete')){await api(endpoint(kind),'DELETE',{version:data.items[kind].version,requestId:nonce()});feedback(t('Belge silindi.'));}
   else if(button.hasAttribute('data-order-doc-view')){const file=await api(endpoint(kind));if(!current())return;dispose();const preview=dialog.querySelector('.shipment-document-preview');preview.hidden=false;dispose=await renderShipmentDocument(preview,file,{current,signal});}
   else{const format=button.hasAttribute('data-order-doc-pdf')?'pdf':'jpeg',filename=brandedDocumentFilename(workspaceExportCompany(state,token.company),kind==='invoice'?'Fatura':'Teslimat Evrakı',format),destination=await prepareReportSave(filename);if(destination==='cancelled'){feedback(downloadStatus(destination));return;}if(!current())return;const file=await api(endpoint(kind));if(!current())return;const blob=await shipmentDocumentFile(file,format);feedback(downloadStatus(await saveReportFile(blob,filename,current,globalThis,destination,{browserDownload:true})));}
  }catch(error){feedback(error.message);}finally{lock(false);await refresh();}
 },{signal});
 dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();},{signal});
 dialog.addEventListener('close',()=>{clearTimeout(timer);events.abort();dispose();},{once:true});
 const schedule=()=>{timer=setTimeout(async()=>{if(session()){if(current()&&!document.hidden)await refresh();schedule();}},10000);};
 window.addEventListener('focus',refresh,{signal});await refresh();schedule();
}
