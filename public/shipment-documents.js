import {brandedDocumentFilename} from './document-filename.js';
import {documentOutputActions} from './document-output-actions.js';
import {openStoredDocumentOutput} from './stored-document-output.js';
import {labelMap as localizedLabels} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,upload} from './api.js';
import {esc,openDialog} from './ui.js';
import {prepareShipmentDocument,shipmentDocumentFile} from './shipment-document-files.js';
import {prepareReportSave,saveReportFile,downloadStatus} from './report-download.js';
import {renderShipmentDocument} from './shipment-document-preview.js';
const labels=localizedLabels({invoice:'Fatura',delivery:'Teslimat evrağı'});
const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(24)),value=>value.toString(16).padStart(2,'0')).join('');
function documentActions(row,kind,canDeleteDocuments){
 if(!row.documents?.[kind])return i18nHtml('<p>Henüz belge eklenmedi.</p>');
 return i18nHtml`<div class="shipment-document-actions">
  <button type="button" class="btn primary" data-document-preview="${kind}">Görüntüle</button>
  ${documentOutputActions({pdf:{attr:'data-document-pdf',value:kind},share:{attr:'data-document-share',value:kind},print:{attr:'data-document-print',value:kind}})}
  ${row.documentTypes?.[kind]==='image/jpeg'?i18nHtml`<button type="button" class="btn" data-document-format="jpeg" data-kind="${kind}">Görseli indir</button>`:''}
  ${kind==='delivery'&&canDeleteDocuments?i18nHtml`<button type="button" class="btn" data-document-delete>Teslimat Evrağını Sil</button>`:''}</div>`;
}
export function shipmentDocumentControls(row){
 return i18nHtml`<div class="shipment-document-controls" aria-label="Sipariş belgeleri">${[['invoice','Fatura'],['delivery','T.Evrağı']].filter(([kind])=>kind==='invoice'||row.documents?.[kind]).map(([kind,letter])=>i18nHtml`<button type="button" data-shipment-document="${kind}" data-present="${!!row.documents?.[kind]}" title="${labels[kind]} · ${kind==='invoice'?i18nT('Dosya ekle, görüntüle veya indir'):i18nT('Görüntüle veya indir')}" aria-label="${esc(row.customerName||i18nT('Kayıt'))} · ${labels[kind]} belgeleri">${letter}</button>`).join('')}<button type="button" class="shipment-document-download" data-shipment-document="download" ${!row.documents?.invoice&&!row.documents?.delivery?'disabled':''}>Belgeleri aç</button></div>`;
}
export function bindShipmentDocuments({model,company,current,endpoint,refresh,invalidate,allowUpload=false,openUploadPage}){
 if(!document.getElementById('shipmentDocumentStyle')){const style=document.createElement('link');style.id='shipmentDocumentStyle';style.rel='stylesheet';style.href='/shipment-document-preview.css';document.head.append(style);}
 async function click(button){
  const kind=button.dataset.shipmentDocument;if(!kind)return false;
  const row=model.data?.items.find(item=>item.id===Number(button.closest('[data-shipment-row]')?.dataset.shipmentRow));if(!row)return true;
  return open(row,kind);
 }
 function open(row,kind){
  const events=new AbortController();let busy=false,nonce=requestId(),previewRequest,previewSource;
  const rights=key=>model.documentPermissions?.[key]||model;
  const kinds=(kind==='download'?Object.keys(labels):[kind]).filter(key=>rights(key).canDocuments!==false&&(key==='invoice'||row.documents?.[key]));
  if(!kinds.length)return true;
  const mutable=!row.customerReadOnly||model.canManage===true||model.canSetCancellation===true;
  const actions=key=>documentActions(row,key,mutable&&rights(key).canDeleteDocuments);
  const sections=kinds.map(key=>i18nHtml`<section class="shipment-document-option"><h3>${labels[key]}</h3><div data-document-actions="${key}">${actions(key)}</div></section>`).join('');
  const canUpload=mutable&&kind!=='download'&&rights(kind).canUpload!==false&&(!row.documents?.[kind]||rights(kind).canReplaceDocuments!==false);
  const form=allowUpload&&canUpload?i18nHtml`<form class="shipment-document-upload"><label>Dosya Ekle<input type="file" name="file" accept="application/pdf,.pdf,image/jpeg,image/png,image/webp" required></label><small>PDF en fazla 4 MB; JPEG, PNG veya WebP en fazla 12 MB. PDF tüm sayfalarıyla, görseller JPEG biçiminde saklanır.${model.canReplaceDocuments===false?'':i18nT(' Bu alanda belge varsa yeni yüklenen belge onun yerini alır.')}</small><div class="shipment-document-actions"><button type="button" class="btn" data-preview-local disabled>Seçilen dosyayı görüntüle</button><button type="submit" class="btn">Seçilen dosyayı yükle</button></div></form>`:'';
  const dialog=openDialog(kind==='download'?i18nT('Sipariş belgeleri'):labels[kind]+' belgeleri',i18nHtml`
   <div class="shipment-document-workspace">
    <div class="shipment-document-controls-pane"><p class="shipment-document-identity">${esc(row.customerName)} · ${esc(row.productName)}</p>
     <div class="shipment-document-options">${sections}</div>${form}
     ${kind==='invoice'&&!allowUpload&&canUpload&&openUploadPage?i18nHtml`<button type="button" class="btn" data-shipment-open-upload>${i18nT('Faturalar’da yükle')}</button>`:''}
     <p class="shipment-document-message" role="status" data-document-message></p>
    </div>
    <section class="shipment-document-preview" aria-label="Belge görüntüleyici" hidden></section>
   </div>`,{className:'shipment-document-dialog shipment-document-dialog--manage'});
  const workspace=dialog.querySelector('.shipment-document-workspace');
  const preview=workspace.querySelector('.shipment-document-preview');
  const uploadForm=workspace.querySelector('form');
  const previewLocalButton=uploadForm?.querySelector('[data-preview-local]');
  const session=()=>current()&&dialog.open&&!events.signal.aborted;
  const alive=()=>session()&&workspace.isConnected;
  const feedback=text=>{if(alive())dialog.querySelector('[data-document-message]').textContent=text;};
  const lock=value=>{
   busy=value;if(!alive())return;
   workspace.querySelectorAll('[data-document-preview],[data-document-format],[data-document-pdf],[data-document-share],[data-document-print],[data-document-delete],[type=submit],[type=file]').forEach(node=>node.disabled=value);
   if(previewLocalButton)previewLocalButton.disabled=value||!uploadForm.elements.file.files.length;
  };
  const clearPreview=()=>{
   previewRequest?.abort();
   previewRequest=undefined;
   previewSource=undefined;
   workspace.removeAttribute('data-preview-open');preview.hidden=true;preview.replaceChildren();
  };
  async function showPreview(data,source){
   clearPreview();
   previewSource=source;
   const request=new AbortController();
   previewRequest=request;
   workspace.dataset.previewOpen='true';
   preview.hidden=false;
   await renderShipmentDocument(preview,data,{current:alive,signal:request.signal,fit:true});
   if(alive()&&previewRequest===request)feedback('');
  }
  const paintActions=key=>{dialog.querySelector(`[data-document-actions="${key}"]`).innerHTML=actions(key);};
  async function removeDelivery(){
   if(!rights('delivery').canDeleteDocuments||!row.documents?.delivery)return;
   lock(true);model.writing=true;invalidate();feedback('');
   let reload=true;
   try{
    const result=await api(endpoint(row.id+'/documents/delivery',row),'DELETE',{version:row.version});
    if(!current())return;
    row.version=result.version;row.documents.delivery=false;
    if(row.documentTypes)delete row.documentTypes.delivery;
    if(alive()){
     clearPreview();paintActions('delivery');
     feedback(i18nT('Teslimat evrağı silindi.'));
     dialog.querySelector('.close')?.focus({preventScroll:true});
    }
   }catch(error){reload=!error.cancelled;feedback(error.cancelled?'':error.message);}
   finally{model.writing=false;lock(false);if(reload&&current())await refresh();}
  }
  dialog.addEventListener('click',async event=>{
   if(event.target.closest('[data-shipment-open-upload]')){
    if(!busy&&alive())await openUploadPage(kind,row);
    return;
   }
   if(event.target.closest('[data-document-delete]')){
    if(!busy&&alive())await removeDelivery();
    return;
   }
   if(event.target.closest('[data-preview-close]')){
    const source=previewSource;
    clearPreview();
    feedback('');
    (source&&!source.disabled?source:dialog.querySelector('.close'))?.focus({preventScroll:true});
    return;
   }
   if(event.target.closest('[data-preview-local]')){
    if(busy||!alive())return;
    lock(true);feedback(i18nT('Belge açılıyor…'));
    try{const file=await prepareShipmentDocument(uploadForm.elements.file.files[0]);if(alive())await showPreview(file,previewLocalButton);}
    catch(error){feedback(error.message);}finally{lock(false);}
    return;
   }
   const output=event.target.closest('[data-document-share],[data-document-print]');
   if(output&&!busy&&alive()){
    const selected=output.dataset.documentShare||output.dataset.documentPrint;
    if(!kinds.includes(selected)||!row.documents?.[selected]||rights(selected).canDocuments===false)return;
    openStoredDocumentOutput({action:output.hasAttribute('data-document-print')?'print':'share',dialog,current:session,
     createFile:async()=>({blob:await shipmentDocumentFile(await api(endpoint(row.id+'/documents/'+selected,row)),'pdf'),filename:brandedDocumentFilename(company(),selected==='invoice'?'Fatura':'Teslimat Evrakı','pdf')})});
    return;
   }
   const control=event.target.closest('[data-document-preview],[data-document-format],[data-document-pdf]');if(!control||busy||!alive())return;
   lock(true);feedback(control.dataset.documentPreview?i18nT('Belge açılıyor…'):i18nT('Dosya hazırlanıyor…'));
   try{
    const selected=control.dataset.documentPreview||control.dataset.documentPdf||control.dataset.kind;
    if(!kinds.includes(selected)||!row.documents?.[selected]||rights(selected).canDocuments===false)return;
    if(control.dataset.documentPreview){
     const data=await api(endpoint(row.id+'/documents/'+selected,row));if(!alive())return;
     await showPreview(data,control);
    }else{
     const format=control.dataset.documentFormat||'pdf',filename=brandedDocumentFilename(company(),selected==='invoice'?'Fatura':'Teslimat Evrakı',format);
     const destination=await prepareReportSave(filename);if(destination==='cancelled'){feedback(downloadStatus(destination));return;}
     if(!alive())return;const data=await api(endpoint(row.id+'/documents/'+selected,row));if(!alive())return;
     const blob=await shipmentDocumentFile(data,format);feedback(downloadStatus(await saveReportFile(blob,filename,alive,globalThis,destination,{browserDownload:true})));
    }
   }catch(error){feedback(error.message);}finally{lock(false);}
  },{signal:events.signal});
  uploadForm?.querySelector('[type=file]').addEventListener('change',()=>{
   nonce=requestId();previewLocalButton.disabled=!uploadForm.elements.file.files.length;
   if(previewSource===previewLocalButton)clearPreview();
  },{signal:events.signal});
  uploadForm?.addEventListener('submit',async event=>{
   event.preventDefault();if(busy||!alive())return;lock(true);model.writing=true;invalidate();feedback(i18nT('Dosya hazırlanıyor…'));
   try{
    const blob=await prepareShipmentDocument(uploadForm.elements.file.files[0]);if(!alive())return;
    const body=new FormData();body.set('file',blob,blob.type==='application/pdf'?'belge.pdf':'belge.jpeg');body.set('version',row.version);body.set('requestId',nonce);feedback(i18nT('Yükleniyor…'));
    const result=await upload(endpoint(row.id+'/documents/'+kind,row),body);if(!current())return;
    row.version=result.version;row.documents??={};row.documents[kind]=true;row.documentTypes??={};row.documentTypes[kind]=blob.type;
    if(alive()){
     clearPreview();paintActions(kind);uploadForm.reset();nonce=requestId();
     if(model.canReplaceDocuments===false)uploadForm.remove();
     feedback(i18nT('Belge eklendi. Görüntüleyebilir veya indirebilirsiniz.'));
    }
   }catch(error){feedback(error.message);}finally{model.writing=false;lock(false);if(current())await refresh();}
  },{signal:events.signal});
  for(const eventName of ['cancel','omnigo-dialog-dismiss'])dialog.addEventListener(eventName,event=>{if(busy)event.preventDefault();},{signal:events.signal});
  dialog.addEventListener('close',()=>{previewRequest?.abort();events.abort();},{once:true});
  return true;
 }
 return {click,open};
}
