import {api} from './api.js';
import {paintContent} from './content-paint.js';
import {html,t} from './i18n.js';
import {latestPageRead} from './latest-page-read.js';
import {bindLiveSearch} from './live-search.js';
import {markPageUpdated} from './page-refresh.js';
import {shipmentDocumentPages} from './shipment-document-pages.js';
import {shipmentTrackingResult,shipmentTrackingSearch} from './shipment-document-tracking-view.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {takeShipmentDocumentTarget} from './shipment-document-navigation.js';
import {bindShipmentDocuments} from './shipment-documents.js';
import {shipmentDocumentCapabilities} from './shipment-permissions.js';
import {scrollTableStart} from './table-page-scroll.js';
import {esc,toast} from './ui.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {bindShipmentOperations} from './shipment-operations.js';
import {copyShipmentCell} from './shipment-copy.js';
import {bindTrackingSelection,shipmentDocumentActions} from './shipment-document-selection.js';

export function shipmentDocumentTrackingContent(view,canManage=false){
 const page=shipmentDocumentPages[view];
 return html`<section class="shipment-document-tracking" data-reporting="${view}" aria-label="${esc(t(page.name))}">
  ${shipmentTrackingSearch(view)}
  ${canManage?shipmentDocumentActions():''}
  <div data-tracking-result></div>
 </section>`;
}

export function bindShipmentDocumentTracking(root,state){
 const token=workspaceToken(state),view=root.dataset.reporting,definition=shipmentDocumentPages[view];
 const capabilities=shipmentDocumentCapabilities(state,view);
 const target=takeShipmentDocumentTarget(state,definition.kind);
 const current=()=>root.isConnected&&state.view===view&&sameWorkspace(state,token);
 const result=root.querySelector('[data-tracking-result]');
 const form=root.querySelector('[data-tracking-search]'),searchInput=form.elements.query;
 const clear=form.querySelector('[data-tracking-clear]'),queryValue=()=>searchInput.value.trim();
 const menus=bindShipmentOperations(result);
 const model={...capabilities,canManage:Boolean(root.querySelector('[data-tracking-selection-count]')),
  queue:'all',query:'',page:0,pageSize:10,data:null,error:'',writing:false,selectedIds:new Set()};
 const snapshot=pageSnapshot(state,view,['query','page','pageSize','data']);
 snapshot.restore(model);
 model.queue='all';
 if(target&&model.canUpload){
  Object.assign(model,{query:'',page:0,data:null});
 }
 searchInput.value=model.query;
 const updateClear=()=>{clear.disabled=searchInput.value==='';};
 updateClear();
 bindLiveSearch(root,{formSelector:'[data-tracking-search]',fieldName:'query',current,isBusy:()=>model.writing||model.loading});
 const reads=latestPageRead(model,current);
 const invalidate=()=>{reads.cancel();result.removeAttribute('aria-busy');};
 const endpoint=(path,row)=>'/shipments/'+path+'?'+new URLSearchParams({company:token.company,city:'all',provider:'all',month:row.month});
 const documents=bindShipmentDocuments({model,current,endpoint,invalidate,refresh:()=>refresh({force:true}),allowUpload:true});
 const selection=model.canManage?bindTrackingSelection(root,{
  model,current,company:token.company,kind:definition.kind,refresh:()=>refresh({force:true}),invalidate,
 }):null;

 function paint(){
  menus.close();
  paintContent(result,shipmentTrackingResult(definition,model));
  selection?.update();
 }

 async function refresh({queue=model.queue,query=model.query,page=model.page,pageSize=model.pageSize,force=false,interactive=false,scroll=false}={}){
  const blocked=()=>!force&&(hasTableTextSelection(root)||result.querySelector('details[data-shipment-product-name][open]'));
  if(!current()||model.writing||blocked()||(!force&&(model.loading||document.querySelector('#dialog[open]'))))return;
  const request=reads.begin();
  result.setAttribute('aria-busy','true');
  try{
   const params=new URLSearchParams({company:token.company,kind:definition.kind,bucket:queue,page,pageSize});
   const data=query
    ?await api('/shipments/search/documents?'+params,'POST',{query},{signal:request.signal})
    :await api('/shipments/document-queue?'+params,'GET',undefined,{signal:request.signal});
   if(!request.active()||blocked())return;
   if(queue!==model.queue||query!==model.query||data.page!==model.page||data.pageSize!==model.pageSize)model.selectedIds.clear();
   const versions=new Map(data.items.map(({id,version})=>[id,version]));
   for(const row of model.data?.items??[]){
    if(model.selectedIds.has(row.id)&&versions.get(row.id)!==row.version)model.selectedIds.delete(row.id);
   }
   Object.assign(model,{data,queue,query,page:data.page,pageSize:data.pageSize,error:''});
   paint();snapshot.save(model);markPageUpdated();
   if(scroll){
    scrollTableStart(root,'.shipment-tracking-scroll');
    result.querySelector('[aria-current="page"]')?.focus({preventScroll:true});
   }
  }catch(error){
   if(!request.active()||blocked())return;
   if(!model.data||[401,403,404].includes(error.status)){
    model.data=null;model.error=error.message;paint();
   }else if(force&&!interactive){paint();toast(error.message);}
   if(interactive)throw error;
  }finally{
   if(request.finish())result.removeAttribute('aria-busy');
  }
 }

 root.reportRefresh=()=>refresh();
 root.pageRefresh=()=>{
  const query=queryValue();
  return refresh({query,page:query===model.query?model.page:0,force:true,interactive:true});
 };
 form.addEventListener('input',updateClear);
 form.addEventListener('submit',event=>{
  event.preventDefault();
  const query=queryValue();
  if(query===model.query&&model.page===0&&model.data&&!model.loading)return;
  void refresh({query,page:0,force:true});
 });
 root.addEventListener('change',event=>{
  const input=event.target;
  if(!input.matches('[data-tracking-size]'))return;
  const pageSize=Number(input.value);
  input.value=String(model.pageSize);
  if(pageSize!==model.pageSize)void refresh({query:queryValue(),page:0,pageSize,force:true,scroll:true});
 });
 root.addEventListener('click',async event=>{
  const button=event.target.closest('button');if(!button||button.disabled||!current()||model.writing)return;
  if(button.dataset.shipmentCopy){
   const row=model.data?.items.find(item=>item.id===Number(button.closest('[data-shipment-row]')?.dataset.shipmentRow));
   if(!row)return;
   try{
    await copyShipmentCell(row,button.dataset.shipmentCopy,button.dataset.shipmentCopyValue);
    if(current())toast(t('Kopyalandı'));
   }catch{
    if(current())toast(t('Kopyalama yapılamadı. Tarayıcının pano iznini kontrol edin.'));
   }
   return;
  }
  if(button===clear){
   searchInput.value='';updateClear();searchInput.focus({preventScroll:true});form.requestSubmit();return;
  }
  if(button.hasAttribute('data-tracking-retry')){void root.pageRefresh().catch(error=>toast(error.message));return;}
  if(button.hasAttribute('data-tracking-page')){
   const query=queryValue();
   const page=Number(button.dataset.trackingPage);
   if(query!==model.query||page!==model.page)void refresh({query,page:query===model.query?page:0,force:true,scroll:true});
   return;
  }
  if(button.hasAttribute('data-shipment-document')){
   if(model.loading)invalidate();
   if(button.dataset.shipmentDocument===definition.kind)void documents.click(button);
  }
 });
 paint();void refresh({force:true});
 if(target?.row&&model.canUpload)documents.open(target.row,target.kind);
}
