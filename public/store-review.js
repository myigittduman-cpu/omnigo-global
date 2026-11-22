import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export function updateReviewNotice(result){
 if(typeof result?.reviewEnvironment!=='boolean')return;
 const active=result.reviewEnvironment;
 document.body.classList.toggle('store-review-active',active);
 let notice=document.getElementById('storeReviewNotice');
 if(!active){notice?.remove();return;}
 if(!document.getElementById('storeReviewStyle')){
  const link=document.createElement('link');link.id='storeReviewStyle';link.rel='stylesheet';link.href='/store-review.css';document.head.append(link);
 }
 if(!notice){notice=document.createElement('div');notice.id='storeReviewNotice';notice.setAttribute('role','note');document.body.prepend(notice);}
 notice.textContent=i18nT('ÖRNEK İNCELEME ALANI · Tüm kayıtlar örnektir. Gerçek müşteri verisi veya pazaryeri bağlantısı kullanılmaz.');
}

globalThis.window?.addEventListener?.('omnigo-language-change',()=>{if(document.getElementById('storeReviewNotice'))updateReviewNotice({reviewEnvironment:true});});
