import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
const stamp=value=>new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
export function salesFreshness(data){
 const relevant=new Set(data.sales.filter(r=>r.integration).map(r=>r.channel));
 const channels=data.channels.filter(c=>relevant.has(c.name)&&c.lastSyncAt);
 if(!channels.length)return data.sales.length?i18nT('Elle girilen satış kayıtları'):i18nT('Aktarım bekleniyor');
 const oldest=channels.map(c=>c.lastSyncAt).sort()[0];
 const delayed=channels.some(c=>Date.now()-Date.parse(c.lastSyncAt)>(['n11','idefix','Pazarama','Koçtaş','Hepsiburada'].includes(c.name)?2:40)*60000);
 const prefix=delayed?i18nT('Aktarım gecikmiş olabilir'):i18nT('Son satış aktarımı');
 return `${prefix}: ${stamp(oldest)}`;
}
export function paymentFreshness(data){
 const payments=data.payments.filter(p=>!p.excludedFromTotals);
 if(!payments.length)return i18nT('Yöneticinin hakediş kaydı bekleniyor');
 const source=i18nT('Yönetici tarafından girilen hakedişler');
 const updated=payments.map(p=>p.lastSyncAt||p.updated).filter(Boolean).sort().at(-1);
 return source+(updated?i18nT(' · Son kayıt: ')+stamp(updated):'');
}
