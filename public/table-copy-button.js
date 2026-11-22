import {esc} from './ui.js';

const copyIcon='<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><rect x="7" y="3" width="10" height="12" rx="1.5"/><path d="M13 17H4a1 1 0 0 1-1-1V7"/></svg>';

/** Keep the clipboard payload separate from the formatted cell text. */
export function tableCopyButton({label,data={},placement=''}){
 const attributes=Object.entries(data).filter(([key,value])=>/^[a-z][a-z0-9-]*$/.test(key)&&value!==undefined)
  .map(([key,value])=>`data-${key}="${esc(value)}"`).join(' ');
 return `<button type="button" class="table-copy-button ${esc(placement)}" ${attributes} aria-label="${esc(label)}" title="${esc(label)}">${copyIcon}</button>`;
}
