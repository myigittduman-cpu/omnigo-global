import {api} from './api.js';
import {paintContent} from './content-paint.js';
import {latestPageRead} from './latest-page-read.js';
import {bindLiveSearch} from './live-search.js';
import {openOrderDetail} from './order-detail.js';
import {markPageUpdated} from './page-refresh.js';
import {copyShipmentCell} from './shipment-copy.js';
import {bindShipmentOperations} from './shipment-operations.js';
import {scrollTableStart} from './table-page-scroll.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {managementOrdersShell,managementOrderRows,managementOrdersPager} from './management-orders-view.js';
import {t,text} from './i18n.js';
import {toast} from './ui.js';

export function bindManagementOrders(root,state){
 if(root.pageRefresh||state.user?.role!=='admin'||!(Number(state.company)>0))return;
 const token=workspaceToken(state);
 const current=()=>root.isConnected&&sameWorkspace(state,token)&&state.user?.role==='admin'&&state.view==='orders';
 const model={query:'',provider:'all',province:'all',page:0,data:null};
 const snapshot=pageSnapshot(state,'managementOrders',['query','provider','province','page','data']);
 snapshot.restore(model);
 const reads=latestPageRead(model,current);
 root.innerHTML=managementOrdersShell(model);
 const form=root.querySelector('form'),body=root.querySelector('tbody');
 const feedback=root.querySelector('[data-management-orders-status]'),pager=root.querySelector('[data-orders-pagination]');
 const menus=bindShipmentOperations(root),frame=body.closest('.management-orders-scroll');
 const filters=()=>Object.fromEntries(new FormData(form));
 const isEditing=()=>hasTableTextSelection(root)||Boolean(root.querySelector('details[open]'))||
  Boolean(document.querySelector('.order-detail-dialog[open]'))||
  Object.entries(filters()).some(([key,value])=>value!==model[key]);
 function paint(data){
  const open=menus.capture();
  if(paintContent(body,data?managementOrderRows(data.items):''))menus.restore(open);
  paintContent(pager,data?managementOrdersPager(data):'');
  feedback.textContent=data?(data.total?text`${data.total} sipariş · En yeni siparişler üstte`:t('Bu filtrelere uygun sipariş bulunmuyor.')):t('Siparişler yükleniyor…');
 }
 async function refresh({background=false,scroll=false}={}){
  if(!current()||(background&&(model.loading||isEditing())))return;
  const request=reads.begin();
  if(!background)frame.setAttribute('aria-busy','true');
  const query=new URLSearchParams({company:token.company,view:'management',query:model.query,provider:model.provider,province:model.province,page:model.page});
  try{
   const data=await api('/admin/orders?'+query,'GET',undefined,{signal:request.signal});
   if(!request.active()||(background&&isEditing()))return;
   model.page=data.page;model.data=data;snapshot.save(model);paint(data);markPageUpdated();
   if(scroll)scrollTableStart(root,'.management-orders-scroll');
  }catch(error){
   if(!request.active())return;
   if([401,403,404].includes(error.status)){model.data=null;snapshot.save(model);paint(null);}
   feedback.textContent=error.message||t('Siparişler yüklenemedi.');
   if(!background)throw error;
  }finally{if(request.finish())frame.removeAttribute('aria-busy');}
 }
 function applyFilters(){
  const next=filters();
  if(Object.entries(next).some(([key,value])=>model[key]!==value)){
   Object.assign(model,next,{page:0});
   feedback.textContent=t('Siparişler yükleniyor…');
  }
  return refresh();
 }
 bindLiveSearch(root,{formSelector:'[data-order-filters]',fieldName:'query',current});
 form.addEventListener('submit',event=>{event.preventDefault();if(current())void applyFilters().catch(()=>{});});
 form.addEventListener('change',event=>{if(event.target.matches('select'))form.requestSubmit();});
 root.addEventListener('click',async event=>{
  const button=event.target.closest('button');
  if(!current()||!button||button.disabled)return;
  if(button.hasAttribute('data-shipment-copy')){
   try{await copyShipmentCell({},button.dataset.shipmentCopy,button.dataset.shipmentCopyValue);if(current())toast(t('Kopyalandı.'));}
   catch(error){if(current())toast(error.message);}
  }else if(button.hasAttribute('data-order-detail')){
   void openOrderDetail(state,Number(button.dataset.orderDetail));
  }else if(button.hasAttribute('data-orders-page')){
   const page=Number(button.dataset.ordersPage);
   if(model.data&&Number.isInteger(page)&&page>=0&&page<Math.ceil(model.data.total/model.data.pageSize)){
    model.page=page;void refresh({scroll:true}).catch(()=>{if(current()&&model.data)model.page=model.data.page;});
   }
  }
 });
 root.pageRefresh=applyFilters;
 root.ordersRefresh=()=>refresh({background:true});
 paint(model.data);void refresh({background:Boolean(model.data)}).catch(()=>{});
}
