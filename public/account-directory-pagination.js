import {html,t} from './i18n.js';

export function directoryPageNumbers(page,count){
 const selected=new Set([1,count,page-1,page,page+1]);
 if(page<=3)for(let i=1;i<=Math.min(5,count);i++)selected.add(i);
 if(page>=count-2)for(let i=Math.max(1,count-4);i<=count;i++)selected.add(i);
 const result=[];let previous=0;
 for(const value of [...selected].filter(n=>n>=1&&n<=count).sort((a,b)=>a-b)){
  if(previous&&value>previous+1)result.push(null);result.push(value);previous=value;
 }
 return result;
}
export function accountDirectoryPagination(data){
 const page=data.page,count=data.pageCount;
 return html`<span class="directory-page-summary">${data.total} ${t('kullanıcı')} · ${t('Sayfa')} ${page} / ${count}</span><nav aria-label="Kullanıcı sayfaları"><button class="btn" type="button" data-directory-goto="${page-1}" ${page<=1?'disabled':''}>Önceki</button>${directoryPageNumbers(page,count).map(n=>n===null?'<span aria-hidden="true">…</span>':html`<button class="btn" type="button" data-directory-goto="${n}" aria-label="${t('Sayfa')} ${n}" ${n===page?'aria-current="page" disabled':''}>${n}</button>`).join('')}<button class="btn" type="button" data-directory-goto="${page+1}" ${page>=count?'disabled':''}>Sonraki</button></nav>`;
}
