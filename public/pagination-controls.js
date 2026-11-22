import {html,t} from './i18n.js';
export function paginationNumbers(page,count){
 const total=Math.max(1,Math.trunc(count)||1),active=Math.min(total-1,Math.max(0,Math.trunc(page)||0));
 const start=Math.max(0,Math.min(active-2,total-5));
 return [...new Set([0,...Array.from({length:Math.min(5,total)},(_,i)=>start+i),total-1])].sort((a,b)=>a-b);
}
/** Numbers preserve each table's existing endpoint, indexing and click handlers. */
export function paginationButtons({page=0,count=1,attribute,disabled=false}){
 if(!/^data-[a-z-]+$/.test(attribute))throw new TypeError('Invalid pagination attribute');
 return html`<span class="pagination-numbers">${paginationNumbers(page,count).map((value,index,pages)=>html`${index&&value-pages[index-1]>1?'<span class="pagination-gap" aria-hidden="true">…</span>':''}<button type="button" class="btn pagination-number" ${attribute}="${value}" aria-label="${value+1}. ${t('sayfa')}" ${value===page?'aria-current="page"':''} ${disabled?'disabled':''}>${value+1}</button>`).join('')}</span>`;
}
