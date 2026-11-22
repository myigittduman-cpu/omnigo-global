import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export async function prepareShipmentDocument(file){
 if(file&&(file.type==='application/pdf'||/\.pdf$/i.test(file.name||''))){
  if(file.size>4*1024*1024||file.size<20)throw new Error(i18nT('Geçerli bir PDF seçin. PDF en fazla 4 MB olabilir.'));
  if(!/^%PDF-(1\.[0-7]|2\.0)[\r\n]/.test(await file.slice(0,16).text()))throw new Error(i18nT('Dosya geçerli bir PDF değil.'));
  return new Blob([file],{type:'application/pdf'});
 }
 return prepareShipmentImage(file);
}
export async function prepareShipmentImage(file){
 if(!file||!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>12*1024*1024)throw new Error(i18nT('En fazla 12 MB JPEG, PNG veya WebP görseli seçin.'));
 const bitmap=await createImageBitmap(file,{imageOrientation:'from-image'});
 try{
  if(bitmap.width*bitmap.height>40000000)throw new Error(i18nT('Görsel çok büyük. Daha küçük bir görsel seçin.'));
  const scale=Math.min(1,3000/Math.max(bitmap.width,bitmap.height)),canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));
  const context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(bitmap,0,0,canvas.width,canvas.height);
  for(const quality of [.94,.86,.76]){
   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));
   if(blob&&blob.size<=1900000)return blob;
  }throw new Error(i18nT('Görsel 2 MB sınırına sığmadı. Daha küçük bir görsel seçin.'));
 }finally{bitmap.close();}
}
export async function shipmentDocumentFile(data,format){
 const bytes=Uint8Array.from(atob(data.bytes),char=>char.charCodeAt(0));
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
