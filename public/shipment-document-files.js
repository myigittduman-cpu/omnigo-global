import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const bytesPerMb=1024*1024;
const invoiceMaxMb=20;

export async function prepareShipmentDocument(file,{invoice=false}={}){
 if(file&&(file.type==='application/pdf'||/\.pdf$/i.test(file.name||''))){
  const maxMb=invoice?invoiceMaxMb:4;
  if(file.size>maxMb*bytesPerMb||file.size<20)throw new Error(i18nT(invoice?'Geçerli bir PDF seçin. PDF en fazla 20 MB olabilir.':'Geçerli bir PDF seçin. PDF en fazla 4 MB olabilir.'));
  if(!/^%PDF-(1\.[0-7]|2\.0)[\r\n]/.test(await file.slice(0,16).text()))throw new Error(i18nT('Dosya geçerli bir PDF değil.'));
  return new Blob([file],{type:'application/pdf'});
 }
 return prepareShipmentImage(file,{invoice});
}
export async function prepareShipmentImage(file,{invoice=false}={}){
 const maxMb=invoice?invoiceMaxMb:12;
 const typeByExtension={jpg:'image/jpeg',jpeg:'image/jpeg',png:'image/png',webp:'image/webp'};
 const extension=file?.name?.split('.').pop()?.toLowerCase();
 const type=file?.type&&file.type!=='application/octet-stream'?file.type:typeByExtension[extension];
 if(!file||!Object.values(typeByExtension).includes(type)||file.size>maxMb*bytesPerMb)throw new Error(i18nT(invoice?'En fazla 20 MB JPEG, PNG veya WebP görseli seçin.':'En fazla 12 MB JPEG, PNG veya WebP görseli seçin.'));
 const source=file.type===type?file:file.slice(0,file.size,type);
 const bitmap=await createImageBitmap(source,{imageOrientation:'from-image'});
 try{
  if(bitmap.width*bitmap.height>40000000)throw new Error(i18nT('Görsel çok büyük. Daha küçük bir görsel seçin.'));
  const canvas=document.createElement('canvas');
  for(const maxSide of invoice?[3000,2250,1680]:[3000]){
   const scale=Math.min(1,maxSide/Math.max(bitmap.width,bitmap.height));
   canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));
   const context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(bitmap,0,0,canvas.width,canvas.height);
   for(const quality of [.94,.86,.76]){
    const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));
    if(blob&&blob.size<=1900000)return blob;
   }
  }throw new Error(i18nT('Görsel 2 MB sınırına sığmadı. Daha küçük bir görsel seçin.'));
 }finally{bitmap.close();}
}
export function decodeShipmentDocumentBytes(base64){
 if(typeof Uint8Array.fromBase64==='function')return Uint8Array.fromBase64(base64);
 const binary=atob(base64),bytes=new Uint8Array(binary.length);
 for(let index=0;index<binary.length;index++)bytes[index]=binary.charCodeAt(index);
 return bytes;
}
export async function shipmentDocumentFile(data,format){
 const bytes=decodeShipmentDocumentBytes(data.bytes);
 if(data.mime==='application/pdf'){
  if(format!=='pdf')throw new Error(i18nT('Yüklenen PDF tüm sayfalarıyla PDF olarak indirilebilir.'));
  return new Blob([bytes],{type:'application/pdf'});
 }
 if(format==='jpeg')return new Blob([bytes],{type:'image/jpeg'});
 if(format!=='pdf')throw new Error(i18nT('Dosya biçimi geçersiz.'));
 const {jsPDF}=await import('./vendor/report-pdf-070.js'),landscape=data.width>data.height;
 const doc=new jsPDF({orientation:landscape?'landscape':'portrait',unit:'mm',format:'a4'});
 const width=doc.internal.pageSize.getWidth(),height=doc.internal.pageSize.getHeight(),scale=Math.min((width-16)/data.width,(height-16)/data.height);
 doc.addImage(bytes,'JPEG',(width-data.width*scale)/2,(height-data.height*scale)/2,data.width*scale,data.height*scale);
 return doc.output('blob');
}
