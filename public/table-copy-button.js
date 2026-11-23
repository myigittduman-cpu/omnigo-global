import {esc} from './ui.js';
import {t} from './i18n.js';
import {withApplicationCopy} from './login-content.js';

const copyIcon='<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="7" y="3" width="10" height="12" rx="1.5"/><path d="M13 17H4a1 1 0 0 1-1-1V7"/></svg>';

/** Keep the clipboard payload separate from the formatted cell text. */
export function tableCopyButton({label,data={},placement=''}){
 const attributes=Object.entries(data).filter(([key,value])=>/^[a-z][a-z0-9-]*$/.test(key)&&value!==undefined)
  .map(([key,value])=>`data-${key}="${esc(value)}"`).join(' ');
 return `<button type="button" class="table-copy-button ${esc(placement)}" ${attributes} aria-label="${esc(label)}" title="${esc(label)}">${copyIcon}</button>`;
}

export async function copyTableText(value){
 if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(value);return;}
 const input=document.createElement('textarea'),active=document.activeElement;
 input.value=value;input.readOnly=true;input.style.cssText='position:fixed;left:-9999px;top:0';document.body.append(input);
 try{input.select();if(!withApplicationCopy(()=>document.execCommand('copy')))throw new Error(t('Kopyalama yapılamadı. Tarayıcının pano iznini kontrol edin.'));}
 finally{input.remove();active?.focus({preventScroll:true});}
}
