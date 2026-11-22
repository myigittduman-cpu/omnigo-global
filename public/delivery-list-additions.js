import {api} from './api.js';
import {loadingState} from './content-state.js';
import {paintContent} from './content-paint.js';
import {orderDate} from './delivery-view.js';
import {html,t} from './i18n.js';
import {latestPageRead} from './latest-page-read.js';
import {paginationButtons} from './pagination-controls.js';
import {bindShipmentSync} from './shipment-sync.js';
import {esc,openDialog,platform} from './ui.js';

const pageSize=10;

function tableRows(model){
 const items=model.data?.items??[];
 return items.map(order=>html`<tr>
  <td>${platform(order.provider)}</td><td><strong>${esc(order.orderNumber)}</strong><small>${esc(order.buyerName)}</small></td>
  <td>${esc(order.city)}</td><td>${esc(orderDate(order.createdAt))}</td>
  <td>${order.products.reduce((quantity,product)=>quantity+Number(product.quantity),0)}</td>
  <td><button type="button" class="btn" data-list-add="${esc(order.selectionKey)}" ${model.saving?'disabled':''}>Listeye ekle</button></td>
 </tr>`).join('')||html`<tr><td colspan="6" class="delivery-additions-empty">${model.loading?loadingState('Siparişler yükleniyor…',{compact:true}):t('Listeye eklenebilecek sipariş yok.')}</td></tr>`;
}

export function openDeliveryListAdditions({endpoint,scope,query,title,current,onAdded,onClose}){
 const dialog=openDialog(title,html`<section class="delivery-additions">
  <p>Pazaryerinde sevk edilen siparişleri bu teslimat listesine ekleyin.</p>
  <div class="delivery-additions-scroll" tabindex="0" role="region" aria-label="Eklenebilir siparişler">
   <table><thead><tr><th scope="col">Pazaryeri</th><th scope="col">Sipariş</th><th scope="col">İl</th><th scope="col">Sipariş tarihi</th><th scope="col">Parça</th><th scope="col">İşlemler</th></tr></thead><tbody></tbody></table>
  </div>
  <div class="delivery-additions-footer"><p role="status" aria-live="polite" data-additions-message></p><nav aria-label="Sipariş sayfaları"></nav></div>
 </section>`,{className:'delivery-additions-dialog'});
 const root=dialog.querySelector('.delivery-additions'),rows=root.querySelector('tbody'),nav=root.querySelector('nav');
 const message=root.querySelector('[data-additions-message]'),scroll=root.querySelector('.delivery-additions-scroll');
 const events=new AbortController(),active=()=>dialog.open&&root.isConnected&&current();
 const model={page:0,data:null,revision:null,error:'',message:'',loading:true,saving:false};
 const reads=latestPageRead(model,active);
 function paint(){
  if(!active())return;
  paintContent(rows,tableRows(model));
  const pages=Math.ceil((model.data?.total??0)/pageSize);
  paintContent(nav,pages>1?paginationButtons({page:model.page,count:pages,attribute:'data-additions-page',disabled:model.saving}):'');
  message.textContent=model.error||model.message;
 }
 async function refresh({page=model.page}={}){
  if(!active())return;
  const request=reads.begin();
  try{
   const data=await api(endpoint(`list-additions?${query}&limit=${pageSize}&page=${page}`),'GET',undefined,{signal:request.signal});
   if(!request.active())return;
   const last=Math.max(0,Math.ceil(data.total/pageSize)-1);
   if(page>last){await refresh({page:last});return;}
   model.data=data;model.page=page;model.revision=data.revision;model.error='';
  }catch(error){if(request.active()&&error.name!=='AbortError')model.error=error.message;}
  finally{if(request.finish())paint();}
 }
 root.addEventListener('click',async event=>{
  const button=event.target.closest('button');if(!button||model.saving||!active())return;
  if(button.hasAttribute('data-additions-page')){
   const page=Number(button.dataset.additionsPage);if(page===model.page)return;
   await refresh({page});if(!active())return;
   scroll.scrollTop=0;nav.querySelector('[aria-current="page"]')?.focus({preventScroll:true});return;
  }
  const order=model.data?.items.find(item=>item.selectionKey===button.dataset.listAdd);
  if(!order)return;
  reads.cancel();model.saving=true;model.message=t('Sipariş ekleniyor…');model.error='';paint();
  try{
   const result=await api(endpoint('list-additions'),'POST',{...scope,orders:order.selectionRecords});
   if(!active())return;
   onAdded(result.selectionKey);
   model.message=t('Sipariş teslimat listesine eklendi.');
   await refresh();
  }catch(error){
   if(!active())return;
   if(error.status===409||error.status===404)await refresh();
   model.message='';model.error=error.message;
  }finally{model.saving=false;paint();}
 },{signal:events.signal});
 dialog.addEventListener('omnigo-dialog-dismiss',event=>{if(model.saving)event.preventDefault();},{signal:events.signal});
 dialog.addEventListener('close',()=>{events.abort();reads.cancel();onClose();},{once:true});
 bindShipmentSync({root,model,current:active,endpoint,signal:events.signal,canRefresh:()=>!model.saving&&!model.loading,refresh});
 paint();void refresh();
}
