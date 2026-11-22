import {api} from './api.js';
import {scrollTableStart,preserveTablePosition} from './table-page-scroll.js';
import {esc,openDialog} from './ui.js';
import {showFeedback} from './save-feedback.js';
import {patchMarketplaceHistory} from './inventory-marketplace-history.js';
import {inventoryHistoryView} from './inventory-history-view.js';
import {beginContentLoad,contentError} from './content-state.js';

export function openInventoryHistory({item=null,endpoint,current}){
 const dialog=openDialog('Stok hareketleri',`${item?`<p><strong>${esc(item.title)}</strong></p>`:''}<p class="muted">Mağaza satışları ve stok güncellemeleri. Tarihler Türkiye saatine göredir.</p><form class="inventory-history-filters"><label>Başlangıç tarihi<input type="date" name="from" aria-label="Hareket başlangıç tarihi"></label><label>Bitiş tarihi<input type="date" name="to" aria-label="Hareket bitiş tarihi"></label><button type="button" class="btn secondary" data-history-clear>Tüm tarihler</button></form><p class="form-error" role="alert"></p><div data-inventory-history aria-live="polite"></div>`,{className:'inventory-dialog inventory-history-dialog'});
 const target=dialog.querySelector('[data-inventory-history]'),form=dialog.querySelector('form'),error=dialog.querySelector('.form-error');
 let cursors=[''],page=0,nextCursor=null,revision=0,timer,busy=false;
 const active=()=>current()&&dialog.open&&target.isConnected;
 async function load({scrollToTable=false,quiet=false,replace=false}={}){
  clearTimeout(timer);if(!active())return;
  const request=++revision;if(!quiet)error.textContent='';const complete=quiet?()=>{}:beginContentLoad(target,'Stok hareketleri yükleniyor…');
  try{
   const params=new URLSearchParams({from:form.elements.from.value,to:form.elements.to.value,before:cursors[page]});
   if(item)params.set('productKey',item.key);
   const data=await api(endpoint('/history?'+params));if(!active()||request!==revision)return;
   nextCursor=data.nextCursor;
   const restore=quiet?preserveTablePosition(target):()=>{};
   if(quiet&&!replace)patchMarketplaceHistory(target,data);
   else target.innerHTML=inventoryHistoryView(data,{product:Boolean(item),page});
   restore();
   if(data.marketplaceManagement&&(data.items||[]).some(row=>row.marketplaceRequest&&!['succeeded','failed','uncertain'].includes(row.marketplaceRequest.state)))timer=setTimeout(()=>void load({quiet:true}),3000);
   if(scrollToTable)scrollTableStart(target,'.inventory-history-table-wrap');
  }catch(failure){if(active()&&request===revision){if(quiet){error.textContent='Hareket durumu yenilenemedi. '+failure.message;timer=setTimeout(()=>void load({quiet:true}),6000);}else target.innerHTML=contentError(failure.message);}}
  finally{complete();}
 }
 const reset=()=>{cursors=[''];page=0;void load();};
 form.onsubmit=event=>{event.preventDefault();reset();};form.onchange=reset;
 form.querySelector('[data-history-clear]').onclick=()=>{form.reset();reset();};
 target.onclick=async event=>{
  const button=event.target.closest('button');if(!button||button.disabled||busy)return;
  if(button.hasAttribute('data-stock-retry')){
   busy=true;button.disabled=true;error.textContent='';clearTimeout(timer);
   try{const result=await api(endpoint('/marketplace-stock'),'POST',{action:'retry',requestId:button.dataset.stockRetry,confirmed:true});if(active()){showFeedback(result.state==='succeeded'?'Güncelleme başarılı':'Talep oluşturuldu',{kind:'success',force:true});await load({quiet:true,replace:true});}}
   catch(failure){if(active()){error.textContent=failure.message;button.disabled=false;}}
   finally{busy=false;}return;
  }
  if(button.hasAttribute('data-history-next')&&nextCursor){cursors[++page]=nextCursor;void load({scrollToTable:true});}
  if(button.hasAttribute('data-history-prev')&&page>0){page--;void load({scrollToTable:true});}
 };
 dialog.addEventListener('close',()=>{clearTimeout(timer);revision++;},{once:true});void load();return dialog;
}
