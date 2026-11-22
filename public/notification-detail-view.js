import {html} from './i18n.js';
import {esc} from './ui.js';

export function notificationFields(entries){
 return html`<dl class="notification-detail-fields">${entries.filter(([,value])=>value!==undefined&&value!==null&&value!=='').map(([label,value,wide=false])=>html`<div${wide?' class="field-wide"':''}><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>`;
}
