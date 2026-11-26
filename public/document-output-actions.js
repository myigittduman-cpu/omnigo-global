import {whatsappIcon} from './document-share.js';
import {html,t} from './i18n.js';
import {esc,icon} from './ui.js';
import {workspaceIcon} from './workspace-icons.js';

const printerIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H4V9h16v8h-3M7 14h10v7H7zM16.5 11h.5"/></svg>';

function outputButton(action,label,symbol){
  if(!action)return '';
  if(!/^data-[a-z][a-z0-9-]*$/.test(action.attr))throw new TypeError('Belge düğmesinin işlem alanı geçersiz.');
  const attribute=action.value==null?action.attr:`${action.attr}="${esc(action.value)}"`;
  return html`<button class="btn document-output-button" type="button" ${attribute} ${action.disabled?'disabled':''} ${action.title?`title="${esc(t(action.title))}"`:''}>${symbol}<span>${esc(t(label))}</span></button>`;
}

export function documentOutputActions({excel,pdf,print,share}){
  if(!excel&&!pdf&&!print&&!share)return '';
  return html`<span class="document-output-actions" role="group" aria-label="Belge işlemleri">${outputButton(excel,'Excel indir',workspaceIcon('excel'))}${outputButton(pdf,'PDF İndir',icon('reports'))}${outputButton(print,'Yazdır',printerIcon)}${outputButton(share,'WhatsApp ile paylaş',whatsappIcon)}</span>`;
}
