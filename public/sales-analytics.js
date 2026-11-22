import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {scrollTableStart} from './table-page-scroll.js';
import {beginContentLoad,contentError} from './content-state.js';
import {enhanceManualDateFilters,syncManualDateFilters} from './manual-date-filters.js';
import {workspaceRange} from './workspace-date-range.js';
import {api} from './api.js';
import {esc,now,openDialog} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {salesRangeResult} from './sales-analytics-view.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {bindTopProducts,bindProductImages} from './top-products.js';

export function defaultSalesRange(month,today=now()) {
 const last=new Date(Date.UTC(Number(month.slice(0,4)),Number(month.slice(5,7)),0)).toISOString().slice(0,10);
 return {from:month+'-01',to:last<today?last:today<month+'-01'?last:today};
}
export async function openSalesAnalytics(state,channel,{products=true}={}) {
 const token=workspaceToken(state),range=workspaceRange(state);
 const dialog=openDialog(i18nText`${channel} · Satışlar ve ürünler`,i18nHtml`<form class="sales-range-form"><div class="sales-presets"><button type="button" data-range="today">Bugün</button><button type="button" data-range="yesterday">Dün</button><button type="button" data-range="month">Bu ay</button><button type="button" data-range="selected">Seçili ay</button></div><div class="sales-range-inputs"><label>Başlangıç tarihi<input type="date" name="from" value="${range.from}" required></label><label>Bitiş tarihi<input type="date" name="to" value="${range.to}" required></label><button class="btn primary" type="submit">Göster</button></div><p class="form-error" role="alert"></p></form><div class="sales-range-result" aria-live="polite"></div>`);
 dialog.classList.add('sales-analysis-dialog');enhanceManualDateFilters(dialog);
 const form=dialog.querySelector('form'),result=dialog.querySelector('.sales-range-result'),error=dialog.querySelector('.form-error');
 let request=0,disposeSlides=()=>{};
 dialog.addEventListener('close',()=>{request++;disposeSlides();},{once:true});
 async function load(page=0,{scrollToTable=false}={}) {
  if(!form.reportValidity())return;
  const from=form.elements.from.value,to=form.elements.to.value;
  if(from>to){error.textContent=i18nT('Başlangıç tarihi bitiş tarihinden sonra olamaz.');return;}
  const id=++request;disposeSlides();error.textContent='';const complete=beginContentLoad(result,'Satışlar hazırlanıyor…');
  form.querySelector('[type=submit]').disabled=true;
  try{
   const query=new URLSearchParams({company:token.company,channel,from,to,page});
   const data=await api('/sales/detail?'+query);
   if(id!==request||!dialog.open||!sameWorkspace(state,token))return;
   result.innerHTML=salesRangeResult({...data,sensitiveVisibility:customerSensitiveVisibility(state,data.sensitiveVisibility)},{products});bindProductImages(result);disposeSlides=bindTopProducts(result);
   result.querySelectorAll('[data-product-page]').forEach(button=>button.onclick=()=>load(Number(button.dataset.productPage),{scrollToTable:true}));
   if(scrollToTable)scrollTableStart(result,'.sales-products table');
  }catch(e){if(id===request&&dialog.open&&sameWorkspace(state,token))result.innerHTML=contentError(e.message);}
  finally{complete();if(id===request)form.querySelector('[type=submit]').disabled=false;}
 }
 form.onsubmit=event=>{event.preventDefault();load();};
 form.querySelectorAll('[data-range]').forEach(button=>button.onclick=()=>{
  const today=now(),yesterday=new Date(Date.parse(today+'T12:00:00Z')-86400000).toISOString().slice(0,10);
  const next=button.dataset.range==='today'?{from:today,to:today}:button.dataset.range==='yesterday'?{from:yesterday,to:yesterday}:defaultSalesRange(button.dataset.range==='month'?today.slice(0,7):state.month,today);
  form.elements.from.value=next.from;form.elements.to.value=next.to;syncManualDateFilters(form);load();
 });
 await load();
}
