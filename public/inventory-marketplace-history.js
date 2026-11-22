import {esc} from './ui.js';
export const stockRequestStatus=state=>state==='succeeded'?{label:'Güncelleme başarılı',tone:'success'}:['failed','uncertain'].includes(state)?{label:'Güncelleme başarısız',tone:'error'}:{label:'Güncelleme bekleniyor',tone:'pending'};
export function marketplaceHistoryCell(request,{writable=false}={}){
 const status=stockRequestStatus(request.state);
 return `<strong class="marketplace-history-state is-${status.tone}">${status.label}</strong><small>${esc(request.provider)} · İstenen stok: ${Number(request.quantity).toLocaleString('tr-TR')} adet</small>${request.error?`<small class="marketplace-history-reason">${esc(request.error)}</small>`:''}${writable&&request.retryable?`<button type="button" class="btn secondary" data-stock-retry="${esc(request.id)}" data-stock-retry-quantity="${Number(request.quantity)}">Yeniden dene</button>`:''}`;
}
export function patchMarketplaceHistory(target,data){
 for(const row of data.items||[]){
  if(!row.marketplaceRequest)continue;
  const request=row.marketplaceRequest,tr=[...target.querySelectorAll('[data-marketplace-request]')].find(node=>node.dataset.marketplaceRequest===request.id);
  if(!tr)continue;
  const cell=tr.querySelector('[data-marketplace-history-state]'),html=marketplaceHistoryCell(request,{writable:data.marketplaceManagement});
  if(cell.innerHTML!==html)cell.innerHTML=html;
  const last=tr.querySelector('[data-marketplace-history-after]'),value=request.state==='succeeded'?Number(request.quantity).toLocaleString('tr-TR'):'—';if(last.textContent!==value)last.textContent=value;
 }
}
