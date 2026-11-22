import {latestPageRead} from './latest-page-read.js';
import {paintContent} from './content-paint.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {scrollTableStart} from './table-page-scroll.js';
import {bindLiveSearch} from './live-search.js';
import {monthDateRange} from './filter-date-range.js';
import {customerCan} from './customer-access.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {markPageUpdated} from './page-refresh.js';
import {openOrderDetail} from './order-detail.js';
import {api} from './api.js';
import {ordersView} from './orders-view.js';
import {bindManagementOrders} from './management-orders.js';
import {bindShipmentOperations} from './shipment-operations.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {now} from './ui.js';
export function bindOrders(app,state){
 const root=app.querySelector('[data-orders]');if(!root||!customerCan(state,'orders'))return;
 if(state.user?.role==='admin')return bindManagementOrders(root,state);
 const token=workspaceToken(state),current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='orders';
 const model={provider:'all',query:'',...monthDateRange(now()),page:0,data:null,error:'',busy:false};
 const reads=latestPageRead(model,current);
 const snapshot=pageSnapshot(state,'orders',["data","provider","query","from","to","page"]);snapshot.restore(model);
 const search=bindLiveSearch(root,{formSelector:'[data-order-filters]',fieldName:'query',current,isBusy:()=>model.busy});
 const menus=bindShipmentOperations(root);
 const paint=()=>{if(current())search.paint(()=>{menus.close();paintContent(root,ordersView(model));});};
 async function refresh({page=model.page}={}){
  if(!current()||model.busy)return;const request=reads.begin();let loaded=false;model.error='';paint();
  try{const data=await api((state.user.role==='admin'?'/admin':'')+'/orders?'+new URLSearchParams({company:token.company,provider:model.provider,query:model.query,from:model.from,to:model.to,page}),'GET',undefined,{signal:request.signal});if(request.active()){model.data=data;markPageUpdated();model.page=data.page;snapshot.save(model);loaded=true;}}
  catch(error){if(request.active())model.error=error.message;}
  finally{if(request.finish())paint();}
  return loaded&&request.active();
 }
 root.addEventListener('submit',event=>{if(!event.target.matches('[data-order-filters]'))return;event.preventDefault();if(model.busy)return;const fields=Object.fromEntries(new FormData(event.target));if(fields.from>fields.to){event.target.elements.to.setCustomValidity(i18nT('Bitiş tarihi başlangıçtan önce olamaz.'));event.target.elements.to.reportValidity();return;}Object.assign(model,fields,{page:0,data:null});void refresh();});
 root.addEventListener('change',event=>{if(event.target.name==='provider')event.target.form.requestSubmit();});
 root.addEventListener('input',event=>{if(['from','to'].includes(event.target.name))event.target.form.elements.to.setCustomValidity('');});
 root.addEventListener('click',event=>{if(event.target.closest('.shipment-sku-cell'))return;const row=event.target.closest('[data-order-id]');if(row&&!event.target.closest('a,input,select')&&!window.getSelection()?.toString()){void openOrderDetail(state,Number(row.dataset.orderId));return;}const button=event.target.closest('button');if(!button||button.disabled||model.busy)return;if(button.hasAttribute('data-orders-page')){const page=Number(button.dataset.ordersPage);void refresh({page}).then(loaded=>{if(loaded&&current()&&!model.error)scrollTableStart(root,'.orders-table-wrap');});}else if(button.hasAttribute('data-orders-refresh'))void refresh();});
 root.ordersRefresh=()=>{if(model.loading)return;const form=root.querySelector('form');if(document.querySelector('.order-detail-dialog[open]')||root.querySelector('details[data-shipment-product-name][open]')||!form||(root.contains(document.activeElement)&&document.activeElement?.matches('input,textarea,select,[contenteditable="true"]'))||['provider','query','from','to'].some(field=>form.elements[field].value!==model[field]))return;return refresh();};
 root.pageRefresh=async()=>{
  if(model.busy)throw new Error(i18nT('Siparişler yükleniyor. Lütfen tamamlanmasını bekleyin.'));
  const form=root.querySelector('form'),fields=Object.fromEntries(new FormData(form));
  if(fields.from>fields.to)throw new Error(i18nT('Başlangıç tarihi bitiş tarihinden sonra olamaz.'));
  if(['provider','query','from','to'].some(key=>fields[key]!==model[key]))Object.assign(model,fields,{page:0});
  await refresh();if(model.error)throw new Error(model.error);
 };
 paint();void refresh();
}
