import {turkeyCities} from './turkey-cities.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const cities=turkeyCities;
const providers=['Hepsiburada','n11','idefix','Pazarama','Koçtaş','PTT AVM','Trendyol'];
const aliases=new Set(['Vizyon Beyaz Eşya','Vizyona','Vizyona Beyaz Eşya','Vizyon Beyaz Eşya Mobilya Oto. Ve Kiralama Paz. Tic. Ltd. Şti.']);
const allCities=value=>['all',i18nT('Tüm şehirler'),i18nT('Tüm Şehirler')].includes(value);
const allProviders=value=>['all',i18nT('Tüm pazaryerleri'),i18nT('Tüm paneller'),i18nT('Tüm Paneller')].includes(value);

export function documentCompanyName(company){
 const raw=String(company?.name||company?.title||'').trim();
 if(!raw)throw new Error(i18nT('Evrakın firması belirlenemedi. Sayfayı yenileyin.'));
 if([...aliases].some(name=>name.toLocaleLowerCase('tr-TR')===raw.toLocaleLowerCase('tr-TR')))return 'Vizyona';
 if(raw.toLocaleLowerCase('tr-TR')==='terzioğlu shop')return 'Terzioğlu Shop';
 let name=raw.replace(/[^\p{L}\p{N} _-]/gu,' ').replace(/\s+/g,' ').trim();
 // Leave room for Turkish city/provider labels within the native filename limit.
 while(new TextEncoder().encode(name).length>80)name=Array.from(name).slice(0,-1).join('');
 if(!name.trim())throw new Error(i18nT('Firma adını kontrol edin.'));
 return name.trim();
}

export function documentDownloadDate(value=new Date()){
 const date=new Date(value);if(Number.isNaN(date.getTime()))throw new Error(i18nT('İndirme tarihi belirlenemedi.'));
 const parts=Object.fromEntries(new Intl.DateTimeFormat('tr-TR',{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric'}).formatToParts(date).map(part=>[part.type,part.value]));
 return `${parts.day}.${parts.month}.${parts.year}`;
}

export function documentFilename({company,city,provider='all',kind,type='pdf',downloadedAt=new Date()}){
 if(!['pdf','xlsx'].includes(type))throw new Error(i18nT('Dosya türünü kontrol edin.'));
 if(!['delivery','shipment'].includes(kind))throw new Error(i18nT('Evrak türünü kontrol edin.'));
 const everyCity=allCities(city),everyProvider=allProviders(provider);
 if(!everyCity&&!cities.includes(city))throw new Error(i18nT('Teslimat ilini seçin.'));
 if(!everyProvider&&!providers.includes(provider))throw new Error(i18nT('Pazaryerini seçin.'));
 const label=kind==='delivery'?i18nT('Teslimat Evrakları'):i18nT('Şehir Dışı Sevkiyat Listesi');
 return brandedDocumentFilename(company,label,type,downloadedAt);
}

export function brandedDocumentFilename(company,title,type='pdf',downloadedAt=new Date()){
 if(!['pdf','xlsx','jpeg','jpg'].includes(type))throw new Error(i18nT('Dosya türünü kontrol edin.'));
 const brand=documentCompanyName(company).normalize('NFC');
 const label=String(title??'').normalize('NFC').replace(/[<>:"/\\|?*\p{Cc}\p{Cf}]/gu,' ').replace(/\s+/g,' ').trim();
 if(!label)throw new Error(i18nT('Evrak adı belirlenemedi.'));
 const prefix=brand+' – '+documentDownloadDate(downloadedAt)+' ';
 let name=prefix;
 const encoder=new TextEncoder();
 for(const letter of label){
  const candidate=name+letter+'.'+type;
  if(candidate.length>160||encoder.encode(candidate).length>236)break;
  name+=letter;
 }
 return name.trimEnd()+'.'+type;
}
