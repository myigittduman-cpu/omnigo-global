import {latestPageRead} from './latest-page-read.js';
import {paintContent} from './content-paint.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {scrollTableStart} from './table-page-scroll.js';
import {bindLiveSearch} from './live-search.js';
import {validateDateInputs} from './date-inputs.js';
import {openDocumentPrint} from './document-print.js';
import {applyDeliveryScope,deliveryWorkspaceScope} from './delivery-scope.js';
import {workspaceCities} from './turkey-cities.js';
import {customerCan} from './customer-access.js';
import {t as i18nT} from './i18n.js';
import {bindShipmentSync} from './shipment-sync.js';
import {api} from './api.js';
import {now} from './ui.js';
import {markPageUpdated} from './page-refresh.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {deliveryView,ready,deliveryPageLimit} from './delivery-view.js';
import {downloadStatus,prepareReportSave} from './report-download.js';
import {fileCenterRecorder} from './file-center-archive.js';
import {deliveryFilename} from './delivery-filename.js';
import {deliveryCities,deliveryCityLabel,deliveryCityQuery,applyDeliveryCities,filterDeliveryCityOptions} from './delivery-city-filter.js';
import {openDeliveryShare} from './delivery-share.js';
import {refreshedDeliverySelection} from './delivery-live-selection.js';
import {openDeliveryCorrection} from './delivery-correction.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {deliverySelectionKey,deliverySelectionEntries,selectPageDeliveries} from './delivery-dispatch-selection.js';

export function bindDelivery(app,state){
 const root=app.querySelector('[data-delivery]');if(!root||!['admin','client'].includes(state.user?.role))return;
 const readOnly=!customerCan(state,'deliveries','selectDocuments'),canExport=!readOnly&&customerCan(state,'deliveries','export');
 const token=workspaceToken(state),current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='deliveries';
 const recordOrders=fileCenterRecorder(state,token,'orders');
 const model={readOnly,canExport,...deliveryWorkspaceScope(state),city:workspaceCities(state).length===81?'all':workspaceCities(state)[0]||'all',queue:'all',provider:'all',date:now(),query:'',from:'',to:'',page:0,data:null,selected:new Set(),manualSelection:false,message:'',busy:false};
 const reads=latestPageRead(model,current);
 const snapshot=pageSnapshot(state,'delivery',["data","city","cities","queue","provider","query","from","to","page","revision"]);snapshot.restore(model);
 if((model.queue==='cancelled'&&!model.sensitiveVisibility.cancellations)||!['all','cancelled'].includes(model.queue)){
  model.queue='all';model.data=null;model.page=0;
 }
 if(model.queue==='all'&&model.page){model.page=0;model.data=null;}
 if(model.data){model.navigation={cities:model.data.cities,queues:model.data.queues};if(!readOnly)model.selected=refreshedDeliverySelection(model,model.data.items);}
 const endpoint=path=>`${state.user.role==='admin'?'/admin':''}/delivery/${path}${path.includes('?')?'&':'?'}company=${token.company}`;
 const listQuery=()=>`city=${encodeURIComponent(model.city)}${deliveryCityQuery(model)}&provider=${encodeURIComponent(model.provider)}&query=${encodeURIComponent(model.query)}&from=${model.from}&to=${model.to}`;
 const filenameContext=()=>({company:state.companies.find(company=>Number(company.id)===Number(token.company)),provider:model.provider});
 const search=bindLiveSearch(root,{formSelector:'[data-delivery-search]',fieldName:'query',current,isBusy:()=>model.busy||model.sharing});
 const paint=()=>{if(current())search.paint(()=>{paintContent(root,deliveryView(model));if(state.user.role!=='admin')root.querySelectorAll('[data-correct-order]').forEach(el=>el.remove());if(model.busy)root.querySelectorAll('button,input,select').forEach(el=>el.disabled=true);});};
 async function refresh({silent=false,manual=false,scrollToTable=false,background=false,page=model.page,focus}={}){
  const blocked=()=>background&&hasTableTextSelection(root);
  if(model.busy||model.sharing||!current()||blocked())return;
  let loaded=false,scopeReset=false;
  const request=reads.begin();
  if(!silent)paint();
  try{
   const data=await api(endpoint(`orders?${listQuery()}&mode=${model.queue}&limit=${deliveryPageLimit(model.queue)}&page=${model.queue==='all'?0:page}`),'GET',undefined,{signal:request.signal});
   if(!request.active()||blocked())return;model.data=data;model.navigation={cities:data.cities,queues:data.queues};model.page=data.page;model.revision=data.revision;scopeReset=applyDeliveryScope(model,data);snapshot.save(model);markPageUpdated();
   model.selected=readOnly?new Set():refreshedDeliverySelection(model,data.items);
   loaded=true;
  }catch(e){if(request.active()&&e.name!=='AbortError'){
   if([401,403,404].includes(e.status)){model.data=null;model.navigation=null;model.selected.clear();snapshot.save(model);}
   model.message=e.message;if(manual)throw e;
  }}finally{
   if(request.finish()&&!blocked()){
    if(scopeReset){void refresh();return;}
    paint();
    if(loaded&&scrollToTable)scrollTableStart(root,'.delivery-orders');
    if(focus)root.querySelector(focus)?.focus({preventScroll:true});
   }
  }
 }
 async function create(type='pdf'){
  if(!canExport||!model.selected.size)return;
  if(!model.date){model.message=i18nT('Sevkiyat tarihini seçin.');paint();return;}
  reads.cancel();model.busy=true;model.message=type==='xlsx'?i18nT('Excel hazırlanıyor…'):i18nT('PDF hazırlanıyor…');paint();
  try{
   const context={...filenameContext(),downloadedAt:new Date()};
   const filename=deliveryFilename(model.cities||model.city,type,context);
   const destination=await prepareReportSave(filename);if(destination==='cancelled'){model.message=downloadStatus('cancelled');return;}
   const document=await api(endpoint('pdf-data'),'POST',{city:model.city,...(model.cities?{cities:model.cities}:{}),deliveryDate:model.date,orders:deliverySelectionEntries(model.data.items,model.selected)});
   if(!current())return;
   const {downloadDelivery}=await import('./delivery-export.js');
   if(current()){
    const status=await downloadDelivery(document,current,destination,type,context,recordOrders);
    if(current())model.message=downloadStatus(status);
   }
  }catch(e){if(current())model.message=e.message;}finally{model.busy=false;paint();}
 }
 root.addEventListener('toggle',event=>{if(event.target.matches('[data-city-filter]')&&event.target.isConnected)model.cityFilterOpen=event.target.open;},true);
 root.addEventListener('input',event=>{if(event.target.matches('[data-city-search]')){model.citySearch=event.target.value;filterDeliveryCityOptions(event.target.closest('form'),model.citySearch);}});
 root.addEventListener('submit',async event=>{
  if(!event.target.matches('[data-delivery-search],[data-city-filter-form]'))return;
  event.preventDefault();if(model.busy||!current())return;const values=new FormData(event.target);
  if(event.target.matches('[data-city-filter-form]')){
   const cities=values.getAll('cities');model.cityFilterError=!cities.length;
   if(!cities.length){model.cityDraft=[];model.cityFilterOpen=true;paint();return;}
   applyDeliveryCities(model,cities);
  }else Object.assign(model,{query:String(values.get('query')||''),from:String(values.get('from')||''),to:String(values.get('to')||''),page:0,data:null,selected:new Set(),manualSelection:false});
  await refresh();
 });
 root.addEventListener('change',async e=>{
  if(model.busy||!current())return;const el=e.target;
  if(readOnly&&el.matches('[data-order],[data-select-all],[data-date]'))return;
  if(el.matches('[data-city-filter-form] input[name="cities"]')){model.cityDraft=[...root.querySelectorAll('[data-city-filter-form] input:checked')].map(input=>input.value);model.cityFilterError=false;root.querySelector('.delivery-city-error').textContent='';return;}
  if(el.matches('[data-order]')){const order=model.data.items.find(order=>String(deliverySelectionKey(order))===el.dataset.order);if(!order||!ready(order))return;const key=deliverySelectionKey(order);el.checked?model.selected.add(key):model.selected.delete(key);model.manualSelection=true;paint();return;}
  if(el.matches('[data-select-all]')){model.selected=selectPageDeliveries(model.data.items.filter(ready),el.checked);model.manualSelection=true;paint();return;}
  if(el.matches('[data-date]')){model.date=el.value;return;}
  if(el.matches('[data-provider]')){model.provider=el.value;model.page=0;model.data=null;model.selected.clear();model.manualSelection=false;model.message='';await refresh();}
 });
 const dismissCities=event=>{if(!current()||event.target.closest('[data-city-filter]'))return;const menu=root.querySelector('[data-city-filter][open]');if(menu){menu.open=false;model.cityFilterOpen=false;}};
 const cityEvents=new AbortController(),cityObserver=new MutationObserver(()=>{if(!root.isConnected){cityEvents.abort();cityObserver.disconnect();}});
 document.addEventListener('pointerdown',dismissCities,{capture:true,signal:cityEvents.signal});document.addEventListener('click',dismissCities,{signal:cityEvents.signal});cityObserver.observe(document.body,{childList:true,subtree:true});
 const previousDispose=root.deliveryDispose;root.deliveryDispose=()=>{cityEvents.abort();cityObserver.disconnect();previousDispose?.();};
 root.addEventListener('click',async e=>{
  const el=e.target.closest('button');if(!el||model.busy||!current())return;
  if(!canExport&&el.matches('[data-create],[data-excel],[data-delivery-share],[data-delivery-print]'))return;
  if(el.matches('[data-create],[data-excel],[data-delivery-share],[data-delivery-print]')&&!validateDateInputs(root.querySelector('.delivery-filters')))return;
  if(el.matches('[data-city-select-all],[data-city-clear]')){const select=el.matches('[data-city-select-all]');model.cityDraft=select?[...model.availableCities]:[];model.cityFilterError=false;model.cityFilterOpen=true;if(model.shippingMode==='nationwide'){root.querySelectorAll('[data-city-filter-form] input[name=cities]').forEach(input=>input.checked=select);root.querySelector('.delivery-city-error').textContent='';}else paint();return;}
  if(el.matches('[data-city-cancel]')){model.cityDraft=null;model.cityFilterError=false;model.cityFilterOpen=false;model.citySearch='';paint();return;}
  if(el.matches('[data-create]')){await create();return;}
  if(el.matches('[data-excel]')){await create('xlsx');return;}
  if(el.matches('[data-correct-order]')){
   if(state.user?.role!=='admin')return;
   model.sharing=true;
   try{await openDeliveryCorrection({id:Number(el.dataset.correctOrder),endpoint,current,onClose:()=>{model.sharing=false;},onSaved:async result=>{
    model.sharing=false;model.selected.clear();model.manualSelection=false;model.page=0;
    model.message=result.ready?i18nT('Bilgiler tamamlandı. Sipariş doğru teslimat ve sevkiyat tablolarına yerleştirildi.'):i18nT('Bilgiler kaydedildi. Eksik veya kapsam dışı alanlar nedeniyle kontrol listesinde kalıyor.');
    model.city=result.ready?result.city:'Diğer / Kontrol';model.cities=null;model.cityDraft=null;model.cityFilterOpen=false;model.queue='all';await refresh();
   }});}catch(error){model.sharing=false;model.message=error.message;paint();}return;
  }
  if(el.matches('[data-delivery-print]')){
   if(!canExport||!model.selected.size||!model.date)return;
   const selection={city:model.city,...(model.cities?{cities:[...model.cities]}:{}),deliveryDate:model.date,orders:deliverySelectionEntries(model.data.items,model.selected)},context=filenameContext();
   model.sharing=true;openDocumentPrint({current,onClose:()=>{model.sharing=false;},createFile:async()=>{
    const data=await api(endpoint('pdf-data'),'POST',selection);
    const file=await (await import('./delivery-export.js')).createDeliveryFile(data,'pdf',context);
    if(current())recordOrders?.(file);
    return file;
   }});return;
  }
  if(el.matches('[data-delivery-share]')){
   if(!model.selected.size)return;
   if(!model.date){model.message=i18nT('Sevkiyat tarihini seçin.');paint();return;}
   const selection={city:model.city,...(model.cities?{cities:model.cities}:{}),deliveryDate:model.date,orders:deliverySelectionEntries(model.data.items,model.selected)};
   model.sharing=true;openDeliveryShare({city:deliveryCityLabel(model),count:selection.orders.length,current,filenameContext:filenameContext(),loadDocument:()=>api(endpoint('pdf-data'),'POST',selection),onFileReady:recordOrders,onClose:()=>{model.sharing=false;}});return;
  }
  if(el.matches('[data-city],[data-delivery-queue]')){
   const city=el.dataset.city||'all',queue=el.dataset.deliveryQueue||'all';
   if(queue===model.queue&&city===model.city&&!model.cities&&!el.matches('[data-city]'))return;
   Object.assign(model,{city,cities:null,cityDraft:null,cityFilterOpen:false,cityFilterError:false,citySearch:'',queue,page:0,data:null,selected:new Set(),manualSelection:false,message:''});
   await refresh();return;
  }
  else if(el.matches('[data-page]'))model.page=Number(el.dataset.page);
  else if(el.matches('[data-refresh]')){model.message='';await refresh();return;}else return;
  model.selected.clear();model.manualSelection=false;model.message='';await refresh({scrollToTable:true});
 });
 root.pageRefresh=async()=>{if(model.busy||model.sharing)throw new Error(i18nT('Devam eden evrak işleminin tamamlanmasını bekleyin.'));model.message='';await refresh({manual:true});};
 root.deliveryRefresh=()=>!model.loading&&!model.busy&&!model.cityFilterOpen&&!root.querySelector('input:focus,select:focus')?refresh({silent:true,background:true}):Promise.resolve();
 bindShipmentSync({root,model,current,endpoint:()=>endpoint('revision'),canRefresh:()=>!model.loading&&!model.busy&&!model.sharing&&!model.cityFilterOpen&&!document.querySelector('dialog[open]')&&!root.querySelector('input:focus,select:focus'),refresh});
 model.loading=!model.data;
 paint();void refresh({silent:true,background:Boolean(model.data)});
}
