import {t as i18nT,html as i18nHtml} from './i18n.js';
import {decodeShipmentDocumentBytes as decode} from './shipment-document-files.js';
const base='/vendor/';
let library,resources;
export class LocalPdfResources{
 async fetch({kind,filename}){
  resources??=fetch(base+'document-pdf-resources-6.4.299.json',{credentials:'same-origin'}).then(response=>{if(!response.ok)throw new Error(i18nT('PDF yazı kaynakları yüklenemedi.'));return response.json();}).catch(error=>{resources=null;throw error;});
  const value=(await resources)[kind]?.[filename];if(!value)throw new Error(i18nT('PDF kaynağı bulunamadı.'));return decode(value);
 }
}
/** Render one page at a time; documents never leave this application. */
export async function renderShipmentDocument(container,data,{current=()=>true,signal,fit=false}={}){
 let disposed=false,task,renderTask,pdf,bitmap,observer,page=1,zoom=1,rendering=false,widthUsed,heightUsed;
 const active=()=>!disposed&&!signal?.aborted&&current()&&container.isConnected;
 const dispose=()=>{
  if(disposed)return;
  disposed=true;
  signal?.removeEventListener('abort',dispose);
  observer?.disconnect();
  renderTask?.cancel();
  void task?.destroy().catch(()=>{});
  bitmap?.close();
  bitmap=null;
 };
 signal?.addEventListener('abort',dispose,{once:true});
 if(!active()){dispose();return dispose;}
 const canvasMarkup=i18nHtml`<canvas aria-label="Belge önizlemesi" role="img" ${fit?'hidden':''}></canvas>`;
 container.innerHTML=i18nHtml`
  <div class="shipment-preview-toolbar">
   <button type="button" class="btn" data-preview-prev disabled>Önceki sayfa</button>
   <span data-preview-page role="status">Belge açılıyor…</span>
   <button type="button" class="btn" data-preview-next disabled>Sonraki sayfa</button>
   <button type="button" class="btn" data-preview-zoom disabled>Yakınlaştır</button>
   ${fit?i18nHtml`<button type="button" class="btn" data-preview-close>Önizlemeyi kapat</button>`:''}
  </div>
  <div class="shipment-preview-page">
   ${fit?i18nHtml`<div class="shipment-preview-sheet-frame"><button type="button" class="shipment-preview-sheet" data-preview-sheet aria-label="Yakınlaştır" disabled>${canvasMarkup}</button></div>`:canvasMarkup}
  </div>`;
 const canvas=container.querySelector('canvas'),status=container.querySelector('[data-preview-page]');
 const frame=container.querySelector('.shipment-preview-page'),sheet=container.querySelector('[data-preview-sheet]');
 const previous=container.querySelector('[data-preview-prev]'),next=container.querySelector('[data-preview-next]'),zoomButton=container.querySelector('[data-preview-zoom]');
 const controls=()=>{
  previous.disabled=rendering||!pdf||page<=1;
  next.disabled=rendering||!pdf||page>=pdf.numPages;
  zoomButton.disabled=rendering||!pdf&&!bitmap;
  if(sheet)sheet.disabled=zoomButton.disabled||canvas.hidden;
 };
 const fitChanged=()=>fit&&(widthUsed!==frame.clientWidth||heightUsed!==frame.clientHeight);
 const show=async()=>{
  if(!active()||rendering)return;
  rendering=true;
  controls();
  let resize=false;
  try{
   const pdfPage=pdf?await pdf.getPage(page):null;
   if(!active())return;
   const original=pdfPage?pdfPage.getViewport({scale:1}):bitmap;
   widthUsed=frame.clientWidth;
   heightUsed=frame.clientHeight;
   const available=fit?Math.max(1,widthUsed-16):Math.min(1000,Math.max(180,container.clientWidth-4));
   const scaleToWidth=available/original.width;
   const scaleToHeight=fit?Math.max(1,heightUsed-16)/original.height:Infinity;
   const baseScale=Math.min(scaleToWidth,scaleToHeight);
   const zoomScale=fit&&zoom>1?Math.max(baseScale*zoom,Math.max(560,available*1.45)/original.width):baseScale*zoom;
   const scale=Math.min(zoomScale,2400/Math.max(original.width,original.height));
   const ratio=Math.min(devicePixelRatio||1,2,Math.sqrt(4000000/(original.width*original.height*scale*scale)));
   canvas.width=Math.max(1,Math.floor(original.width*scale*ratio));
   canvas.height=Math.max(1,Math.floor(original.height*scale*ratio));
   canvas.classList.toggle('is-zoomed',zoom>1);
   if(fit){
    canvas.style.width=Math.max(1,Math.floor(original.width*scale))+'px';
    canvas.style.height=Math.max(1,Math.floor(original.height*scale))+'px';
   }
   const context=canvas.getContext('2d');
   if(pdfPage){
    renderTask=pdfPage.render({canvasContext:context,viewport:pdfPage.getViewport({scale}),transform:ratio===1?null:[ratio,0,0,ratio,0,0]});
    await renderTask.promise;
    pdfPage.cleanup();
   }
   else{context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(bitmap,0,0,canvas.width,canvas.height);}
   if(active()){
    canvas.hidden=false;
    status.textContent=pdf?`Sayfa ${page} / ${pdf.numPages}`:i18nT('Görsel önizlemesi');
    zoomButton.textContent=zoom===1?i18nT('Yakınlaştır'):i18nT('Sayfaya sığdır');
    sheet?.setAttribute('aria-label',zoomButton.textContent);
    resize=fitChanged();
   }
  }catch(error){
   if(active()){
    if(fit)canvas.hidden=true;
    status.textContent=i18nT('Bu sayfa görüntülenemedi. Belgeyi indirme seçeneğini kullanabilirsiniz.');
   }
  }finally{
   rendering=false;
   if(active()){
    controls();
    if(resize)void show();
   }
  }
 };
 try{
  const local=data instanceof Blob;
  const mime=local?data.type:data.mime;
  if(mime==='application/pdf'){
   library??=import('./vendor/document-pdf-6.4.299.js').catch(error=>{library=null;throw error;});
   const bytes=local?new Uint8Array(await data.arrayBuffer()):decode(data.bytes);
   const lib=await library;if(!active()){dispose();return dispose;}
   lib.GlobalWorkerOptions.workerSrc=base+'document-pdf-worker-6.4.299.js';
   task=lib.getDocument({data:bytes,BinaryDataFactory:LocalPdfResources,useWorkerFetch:false,useWasm:false,disableFontFace:true,enableXfa:false,disableWorkerRendering:true,isEvalSupported:false});
   task.onPassword=()=>{void task.destroy();};
   pdf=await task.promise;
  }else if(mime==='image/jpeg'){
   const image=await createImageBitmap(local?data:new Blob([decode(data.bytes)],{type:mime}));
   if(!active()){image.close();dispose();return dispose;}
   bitmap=image;
   previous.hidden=true;
   next.hidden=true;
  }
  else throw new Error(i18nT('Belge biçimi görüntülenemiyor.'));
  if(!active()){dispose();return dispose;}
  const changePage=offset=>{
   const target=page+offset;
   if(!pdf||target<1||target>pdf.numPages||rendering)return;
   page=target;
   if(fit)frame.scrollTo(0,0);
   void show();
  };
  const toggleZoom=()=>{
   if(rendering)return;
   zoom=zoom===1?1.7:1;
   if(fit&&zoom===1)frame.scrollTo(0,0);
   void show();
  };
  previous.onclick=()=>changePage(-1);
  next.onclick=()=>changePage(1);
  zoomButton.onclick=toggleZoom;
  if(sheet)sheet.onclick=toggleZoom;
  await show();
  if(fit&&active()&&typeof ResizeObserver==='function'){
   observer=new ResizeObserver(()=>{if(fitChanged())void show();});
   observer.observe(frame);
  }
 }catch(error){
  const wasActive=active();
  dispose();
  if(wasActive){
   const message=i18nT('Belge açılamadı. Şifreli veya bozuk PDF olabilir. İndir düğmesiyle dosyayı alabilirsiniz.');
   if(fit)status.textContent=message;
   else container.textContent=message;
  }
 }
 return dispose;
}
