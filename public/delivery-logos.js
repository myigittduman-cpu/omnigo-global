import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {marketplaceBrand} from './marketplace-branding.js';
import {companyExportLogo,deliveryExportCompany} from './company-export-brand.js';
export const deliveryLogoFiles={'Koçtaş':marketplaceBrand('Koçtaş').file,'n11':'n11.jpg','Pazarama':marketplaceBrand('Pazarama').file,'idefix':'idefix.png','Hepsiburada':'hepsiburada.png','PTT AVM':'pttavm-official-09118.svg'};
async function loadImage(url,label){
 const source=new URL(url,location.origin);if(source.origin!==location.origin)throw new Error(i18nT('Logo kaynağı geçersiz.'));
 const image=new Image();let timer;
 try{
  // Keep the image on the authenticated same-origin URL. The portal CSP blocks blob: images.
  await new Promise((resolve,reject)=>{
   const fail=()=>reject(new Error(i18nText`${label} logosu yüklenemedi. Sayfayı yenileyip yeniden deneyin.`));
   timer=setTimeout(fail,15000);image.onload=resolve;image.onerror=fail;image.src=source.href;
  });
  if(!image.naturalWidth||!image.naturalHeight)throw new Error(i18nText`${label} logosu okunamadı.`);
  const scale=Math.min(1,800/Math.max(image.naturalWidth,image.naturalHeight));
  const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
  canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
  return {data:canvas.toDataURL('image/png'),width:canvas.width,height:canvas.height};
 }finally{clearTimeout(timer);image.onload=null;image.onerror=null;}
}
export async function deliveryLogos(snapshot,context={}){
 const company=deliveryExportCompany(snapshot,context);
 const images={};
 for(const provider of new Set(snapshot.orders.map(order=>order.provider))){
  const file=deliveryLogoFiles[provider]||marketplaceBrand(provider)?.file;if(!file)throw new Error(i18nT('Pazaryeri logosu bulunamadı.'));
  images[provider]=await loadImage('/assets/'+file,provider);
 }
 images.sender=await companyExportLogo(company);
 return images;
}
