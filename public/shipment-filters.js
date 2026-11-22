import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {visibleShipmentStatuses} from './shipment-status.js';
import {esc} from './ui.js';
export const emptyShipmentFilters=()=>({invoiced:'',cancelled:'',shipped:'',rowStatus:'',customerType:'',dateFrom:'',dateTo:''});
export const shipmentFilterCount=filters=>Object.values(filters||{}).filter(Boolean).length;
export function shipmentFilterMenu(model){
 const values=model.filterDraft||emptyShipmentFilters(),count=shipmentFilterCount(model.filters);
 const fields=[['invoiced','Fatura durumu',[['yes','Fatura kesildi'],['no','Fatura kesilmedi']]],['rowStatus',i18nT('Gerçek sevkiyat'),[...visibleShipmentStatuses(model.showCancellations).map(status=>[status.value,status.label]),['none','Sevk bekliyor']]],['customerType',i18nT('Müşteri tipi'),[['Bireysel','Bireysel'],['Kurumsal','Kurumsal']]]];
 const lastDay=new Date(Number(model.month.slice(0,4)),Number(model.month.slice(5)),0).getDate();
 return i18nHtml`<details class="shipment-filters" data-shipment-filters><summary class="btn">Filtrele${count?' ('+count+')':''}</summary><div class="shipment-filter-panel"><div class="shipment-filter-heading"><strong>Tablo filtreleri</strong><button type="button" class="text-btn" data-shipment-filter-close>← Geri</button></div><div class="shipment-filter-fields">${[['dateFrom',i18nT('Başlangıç tarihi')],['dateTo',i18nT('Bitiş tarihi')]].map(([key,label])=>i18nHtml`<label for="shipment-${key}">${label}<input id="shipment-${key}" type="date" min="${model.month}-01" max="${model.month}-${lastDay}" data-shipment-filter="${key}" value="${esc(values[key])}"></label>`).join('')}${fields.map(([key,label,options])=>i18nHtml`<label for="shipment-${key}">${i18nT(label)}<select id="shipment-${key}" data-shipment-filter="${key}"><option value="">Tümü</option>${options.map(([value,text])=>i18nHtml`<option value="${value}" ${values[key]===value?'selected':''}>${i18nT(text)}</option>`).join('')}</select></label>`).join('')}</div><div class="shipment-filter-actions"><button type="submit" class="btn primary" ${model.busy?'disabled':''}>Uygula</button><button type="button" class="btn" data-shipment-filter-reset>Filtreleri temizle</button></div></div></details>`;
}
export function bindShipmentFilters(root,current){
 let events=null,observer=null;
 const cleanup=()=>{events?.abort();observer?.disconnect();events=null;observer=null;};
 const close=(menu,focus=false)=>{menu.open=false;cleanup();if(focus)menu.querySelector('summary')?.focus({preventScroll:true});};
 root.addEventListener('toggle',event=>{
  const menu=event.target;if(!menu.matches('[data-shipment-filters]'))return;cleanup();if(!menu.open)return;
  events=new AbortController();const {signal}=events;
  document.addEventListener('pointerdown',event=>{if(!menu.contains(event.target))close(menu);},{signal});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();close(menu,true);}},{signal});
  observer=new MutationObserver(()=>{if(!menu.isConnected||!current())cleanup();});observer.observe(document.body,{childList:true,subtree:true});
 },true);
 root.addEventListener('click',event=>{if(event.target.closest('[data-shipment-filter-close]'))close(event.target.closest('details'),true);});
}
