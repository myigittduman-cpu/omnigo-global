import {t,text} from './i18n.js';
// Apply to server-owned status descriptions, never customer text or stored values.
export function displayStatus(value){if(typeof value!=='string')return value;const whole=t(value);return whole!==value?whole:value.split(' / ').map(t).join(' / ');}
export function connectionMessage(value){
 if(typeof value!=='string')return value;
 const match=value.match(/^(\d+) siparişin kaynak kontrolü bekleniyor\. Diğer siparişler aktarılıyor; çelişkili tutarlar yerine son doğrulanmış satış özeti korunuyor\.$/);
 return match?text`${match[1]} siparişin kaynak kontrolü bekleniyor. Diğer siparişler aktarılıyor; çelişkili tutarlar yerine son doğrulanmış satış özeti korunuyor.`:t(value);
}
