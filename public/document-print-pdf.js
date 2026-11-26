import {LocalPdfResources} from './shipment-document-preview.js';

export const pdfPrintBatchSize=50;

export async function openPdfPrintJob(blob,current){
 if(!document.querySelector('link[href="/document-print.css"]')){const style=document.createElement('link');style.rel='stylesheet';style.href='/document-print.css';document.head.append(style);}
 const lib=await import('./vendor/document-pdf-6.4.299.js');lib.GlobalWorkerOptions.workerSrc='/vendor/document-pdf-worker-6.4.299.js';
 const task=lib.getDocument({data:new Uint8Array(await blob.arrayBuffer()),BinaryDataFactory:LocalPdfResources,useWorkerFetch:false,useWasm:false,disableFontFace:true,enableXfa:false,disableWorkerRendering:true,isEvalSupported:false});
 const root=document.createElement('section');root.className='document-print-pages';root.setAttribute('aria-hidden','true');document.body.append(root);
 let renderTask,disposed=false;
 const dispose=()=>{
  if(disposed)return;
  disposed=true;
  renderTask?.cancel();
  root.remove();
  void task.destroy().catch(()=>{});
  document.body.classList.remove('document-printing');
 };
 const active=()=>!disposed&&current();
 try{
  const pdf=await task.promise;
  const render=async batch=>{
   document.body.classList.remove('document-printing');
   root.replaceChildren();
   const first=batch*pdfPrintBatchSize+1,last=Math.min(first+pdfPrintBatchSize-1,pdf.numPages);
   const pixels=Math.min(3000000,75000000/(last-first+1));
   try{
    for(let number=first;number<=last;number++){
     if(!active())return false;
     const page=await pdf.getPage(number);
     if(!active())return false;
     const base=page.getViewport({scale:1});
     const viewport=page.getViewport({scale:Math.min(2,Math.sqrt(pixels/(base.width*base.height)))});
     const canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
     renderTask=page.render({canvasContext:canvas.getContext('2d'),viewport});
     try{await renderTask.promise;}finally{renderTask=null;page.cleanup();}
     if(!active())return false;
     root.append(canvas);
    }
    if(!active())return false;
    document.body.classList.add('document-printing');
    return true;
   }finally{
    if(!active())dispose();
   }
  };
  return {pages:pdf.numPages,render,dispose};
 }catch(error){dispose();throw error;}
}
