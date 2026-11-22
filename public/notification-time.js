import {t as i18nT,text as i18nText,html as i18nHtml,currentLocale} from './i18n.js';
import {esc} from './ui.js';
const formatter=()=>new Intl.DateTimeFormat(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
export function notificationTime(value){
 if(typeof value!=='string'||!value)return '';
 // Legacy SQL timestamps, like the ISO timestamps in the feed, are stored in UTC.
 const source=/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/.test(value)?value.replace(' ','T')+'Z':value;
 const date=new Date(source);if(!Number.isFinite(date.getTime()))return '';
 return i18nHtml`<time class="notification-time" datetime="${esc(date.toISOString())}" title="Türkiye saati">${esc(formatter().format(date))}</time>`;
}
