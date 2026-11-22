import {LocalPdfResources} from './shipment-document-preview.js';
import {t} from './i18n.js';
export async function preparePdfPrint(blob,current){
 if(!document.querySelector('link[href="/document-print.css"]')){const style=document.createElement('link');style.rel='stylesheet';style.href='/document-print.css';document.head.append(style);}
 const lib=await import('./vendor/document-pdf-6.4.299.js');lib.GlobalWorkerOptions.workerSrc='/vendor/document-pdf-worker-6.4.299.js';
 const task=lib.getDocument({data:new Uint8Array(await blob.arrayBuffer()),BinaryDataFactory:LocalPdfResources,useWorkerFetch:false,useWasm:false,disableFontFace:true,enableXfa:false,disableWorkerRendering:true,isEvalSupported:false});
 const root=document.createElement('section');root.className='document-print-pages';root.setAttribute('aria-hidden','true');document.body.append(root);
 const dispose=()=>{root.remove();void task.destroy().catch(()=>{});document.body.classList.remove('document-printing');};
 try{
  const pdf=await task.promise;if(pdf.numPages>50)throw new Error(t('Bu belge 50 sayfadan uzun. PDF dosyasını indirip belge uygulamanızdan yazdırın.'));
  for(let n=1;n<=pdf.numPages;n++){
   if(!current()){dispose();return ()=>{};}const page=await pdf.getPage(n),base=page.getViewport({scale:1}),scale=Math.min(2,Math.sqrt(3000000/(base.width*base.height))),viewport=page.getViewport({scale});
   const canvas=document.createElement('canvas');canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
   await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
   root.append(canvas);
   page.cleanup();
  }
  if(!current()){dispose();return ()=>{};}document.body.classList.add('document-printing');return dispose;
 }catch(error){dispose();throw error;}
}
