import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Export branding always belongs to the selected company, never the portal operator.
export const exportCompanyName=company=>String(company?.name||company?.title||'Firma').trim();
export const exportCompanyFilename=company=>exportCompanyName(company).replace(/[^\p{L}\p{N} _-]/gu,'').slice(0,65)||'Firma';
// The document snapshot is authoritative; never borrow another customer's branding.
export function deliveryExportCompany(snapshot,context={}){
 const id=Number(snapshot.companyId),contextId=Number(context.company?.id);
 if(!Number.isSafeInteger(id)||id<1||(context.company&&contextId!==id))throw new Error(i18nT('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
 return {...snapshot.sender,id,name:context.company?.name||snapshot.sender?.name||snapshot.sender?.title||'Firma',
  hasLogo:snapshot.sender?.hasLogo??context.company?.hasLogo,
  brandingVersion:snapshot.sender?.brandingVersion??context.company?.brandingVersion};
}
export function companyLogoSource(company,{fit=true,payment=false}={}){
 if(!Number.isSafeInteger(Number(company?.id))||Number(company.id)<1)throw new Error(i18nT('Rapor firması belirlenemedi. Sayfayı yenileyin.'));
 return '/api/management/logo?'+(payment?'fit=payment&':fit?'fit=1&':'')+'company='+Number(company.id);
}
export async function companyExportLogo(company){
 if(!company?.hasLogo)return null;
 const source=companyLogoSource(company,{payment:true})+'&v='+Number(company.brandingVersion||0);
 const image=new Image();let timer;
 try{
  await new Promise((resolve,reject)=>{
   const fail=()=>reject(new Error(i18nT('Firma logosu yüklenemedi. Lütfen yeniden deneyin.')));
   timer=setTimeout(fail,15000);image.onload=resolve;image.onerror=fail;
   image.src=source;
  });
  if(!image.naturalWidth||!image.naturalHeight)throw new Error(i18nT('Firma logosu okunamadı.'));
  const scale=Math.min(1,800/Math.max(image.naturalWidth,image.naturalHeight));
  const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
  canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
  return {data:canvas.toDataURL('image/png'),width:canvas.width,height:canvas.height};
 }finally{clearTimeout(timer);image.onload=null;image.onerror=null;}
}
export function drawCompanyPdfLogo(doc,logo,x,y,width,height){
 if(!logo?.width||!logo?.height)return;
 const scale=Math.min(width/logo.width,height/logo.height);
 const w=logo.width*scale,h=logo.height*scale;
 doc.addImage(logo.data,'PNG',x+(width-w)/2,y+(height-h)/2,w,h);
}
