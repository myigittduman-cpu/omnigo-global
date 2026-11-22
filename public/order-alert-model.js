import {t as i18nT,text as i18nText,html as i18nHtml,currentLanguage} from './i18n.js';
import {money} from './ui.js';
import {marketplaceVisible} from './marketplace-visibility.js';
export const orderProviders={hepsiburada:'Hepsiburada',n11:'n11',idefix:'İdefix',pttavm:'PttAvm',pazarama:'Pazarama',koctas:'Koçtaş',teknosa:'Teknosa',trendyol:'Trendyol'};
export function orderAlertGroups(items){
 const groups=new Map(),seen=new Set();
 for(const item of items){
  if(!Number.isSafeInteger(item.id)||seen.has(item.id)||(!orderProviders[item.provider]&&!(item.provider==='portal'&&['support','user','operation','shipment','resolved'].includes(item.type)))||!marketplaceVisible(item.provider))continue;
  seen.add(item.id);const key=`${item.companyId}:${item.provider}:${item.type||'order'}:${item.status||''}:${item.noticeId||['integration','operation','shipment','support','user'].includes(item.type)?item.id:''}`;
  const group=groups.get(key)||{...item,count:0};group.count++;groups.set(key,group);
 }
 return [...groups.values()];
}
export function orderAlertText(group){
 if(['support','user'].includes(group.type))return {title:group.title||i18nT('Müşteri bildirimi'),body:group.body||i18nT('İş Ortağım’da değişiklik yapıldı.')};
 if(['operation','shipment'].includes(group.type))return {title:[orderProviders[group.provider],group.title].filter(Boolean).join(' · ')||'Omnigo',body:[group.company,group.body].filter(Boolean).join(' · ')};
 if(group.type==='integration'&&group.status==='recovered')return {title:i18nText`${orderProviders[group.provider]} · Bağlantı düzeldi`,body:i18nText`${group.company} · Başarılı veri aktarımı doğrulandı.`};
 if(group.type==='integration')return {title:i18nText`${orderProviders[group.provider]} · Bağlantı / aktarım sorunu`,body:i18nText`${group.company} · Yönetici panelinden bağlantıyı kontrol edin.`};
 return {title:i18nText`${orderProviders[group.provider]} · Yeni sipariş`,body:`${group.company} · ${group.count===1?i18nT('Yeni bir sipariş geldi.'):i18nText`${group.count} yeni sipariş geldi.`}`};
}

export function orderNotificationPresentation(group){
 if(currentLanguage()!=='tr')return localizedNotification(group);
 if(typeof group.presentation?.title==='string'&&typeof group.presentation?.body==='string')return {...group.presentation,title:notificationTitle(group.presentation.title)};
 const sources={hepsiburada:'Hepsiburada’dan',n11:'n11’den',idefix:'idefix’ten',pttavm:'PTT AVM’den',pazarama:'Pazarama’dan',koctas:i18nT('Koçtaş’tan'),teknosa:'Teknosa’dan',trendyol:'Trendyol’dan',amazon:'Amazon’dan'};
 const name=String(group.company||'').replace(/[\p{C}\p{Z}]+/gu,' ').trim(),company=name.toLocaleLowerCase('tr-TR')==='vizyona beyaz eşya'?'Vizyona':name;
 const amount=Number.isSafeInteger(group.amountCents)&&group.amountCents>=0&&group.amountCents<=999999999999?i18nText`${(group.amountCents/100).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})}₺ tutarında `:'';
 const detail=!group.type||group.type==='order'?i18nText`${sources[group.provider]||'Pazaryerinden'} ${amount}yeni ${group.count===1?'bir':group.count} siparişiniz var.`:group.type==='operation'&&group.events?.includes('cancelled')?i18nT('Sipariş iptal edildi.'):[group.title,group.body].filter(Boolean).join('. ');
 return {title:notificationTitle(),body:(company?company+'; ':'')+detail};
}
export function desktopNotificationPresentation(group){
 // Windows app identity is fixed in previously installed packages.
 return {...orderNotificationPresentation(group),title:notificationTitle(group.presentation?.title)==='Omnigo Global İş Ortağım'?'OG Müşteri Portalı':'OG Yönetici Portalı'};
}
function localizedNotification(group){
 const rawName=String(group.company||'').replace(/[\p{C}\p{Z}]+/gu,' ').trim();
 const company=rawName.toLocaleLowerCase('tr-TR')==='vizyona beyaz eşya'?'Vizyona':rawName;
 const provider=orderProviders[group.provider]||i18nT('Pazaryeri');
 const title=notificationTitle(group.presentation?.title);
 let body;
 if(!group.type||group.type==='order'){
  const count=group.count||1;
  const validAmount=Number.isSafeInteger(group.amountCents)&&group.amountCents>=0&&group.amountCents<=999999999999;
  body=count>1?i18nText`${company}; ${provider}'dan ${count} yeni siparişiniz var.`:validAmount?i18nText`${company}; ${provider}'dan ${money(group.amountCents)} tutarında yeni bir siparişiniz var.`:i18nText`${company}; ${provider}'dan yeni bir siparişiniz var.`;
 }else if(group.type==='operation'&&group.events?.includes('cancelled'))body=i18nText`${company}; Sipariş iptal edildi.`;
 else body=(company?company+'; ':'')+[group.title,group.body].filter(Boolean).map(i18nT).join('. ');
 return {title,body:company?body:body.replace(/^;\s*/,'')};
}

function notificationTitle(value){return ['OG İş Ortağım','Omnigo Global İş Ortağım','OG Müşteri Portalı','Omnigo Global Müşteri Portalı'].includes(value)?'Omnigo Global İş Ortağım':'Omnigo Global Yönetici Portalı';}
