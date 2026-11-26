import {pageSnapshot} from './workspace-page-cache.js';
import {paintContent} from './content-paint.js';
import {scrollTableStart} from './table-page-scroll.js';
import {bindShipmentColumnLayout} from './shipment-column-layout.js';
import {bindLiveSearch} from './live-search.js';
import {openDocumentPrint} from './document-print.js';
import {workspaceCities} from './turkey-cities.js';
import {shipmentCapabilities,applyShipmentCapabilities} from './shipment-permissions.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {t as i18nT,text as i18nText} from './i18n.js';
import {markPageUpdated} from './page-refresh.js';
import {prepareReportSave,downloadStatus} from './report-download.js';
import {fileCenterRecorder} from './file-center-archive.js';
import {documentFilename} from './document-filename.js';
import {bindShipmentOperations} from './shipment-operations.js';
import {bindShipmentEditSession} from './shipment-edit-session.js';
import {bindShipmentPresence} from './shipment-presence.js';
import {bindShipmentSync} from './shipment-sync.js';
import {defaultShipmentMonth} from './shipment-calendar.js';
import {shipmentRowStatus} from './shipment-status.js';
import {shipmentLayoutFor} from './shipment-layout.js';
import {confirmShipmentInvoice,confirmShipmentStatus} from './shipment-status-confirmation.js';
import {copyShipmentCell} from './shipment-copy.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {bindShipmentEditor} from './shipment-editor.js';
import {bindShipmentDocuments} from './shipment-documents.js';
import {openShipmentDocumentWorkspace} from './shipment-document-navigation.js';
import {bindShipmentShare} from './shipment-share.js';
import {bindShipmentBulk} from './shipment-bulk.js';
import {api} from './api.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {shipmentTable,shipmentRow,shipmentsView,shipmentSelection,shipmentProviders} from './shipments-view.js';
import {emptyShipmentFilters,shipmentFilterCount,bindShipmentFilters} from './shipment-filters.js';
import {bindShipmentSidebar} from './shipment-sidebar.js';
export function bindShipments(app,state,{navigate}={}){
 const root=app.querySelector('[data-shipments]');if(!root||!['admin','client'].includes(state.user?.role))return;
 const token=workspaceToken(state),current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='shipments';
 const recordOrders=fileCenterRecorder(state,token,'orders');
 const defaultProvider=shipmentProviders[0][0];
 const selectedLayout=()=>shipmentLayoutFor(state.user?.preferences?.preferences,token.company);
 const model={...shipmentCapabilities(state),layout:selectedLayout(),availableCities:[...workspaceCities(state)],revision:null,city:'all',provider:defaultProvider,month:defaultShipmentMonth(),draftProvider:defaultProvider,picker:null,page:0,query:'',searchDraft:'',filters:emptyShipmentFilters(),filterDraft:emptyShipmentFilters(),data:null,busy:false,exporting:false,message:'',error:false};
 model.showCancellations=customerSensitiveVisibility(state,{},'shipments').cancellations;
 model.canSetCancellation=state.user.role==='admin';
 model.screen='details';
 const entrySelection=state.shipmentSelection;
 if(entrySelection){Object.assign(model,entrySelection);model.draftProvider=model.provider;model.screen='details';delete state.shipmentSelection;}
 if(!shipmentProviders.some(([name])=>name===model.provider)){model.provider=defaultProvider;model.draftProvider=defaultProvider;}
 const snapshot=pageSnapshot(state,'shipments',["data","city","provider","month","page","query","searchDraft","filters","filterDraft","revision"]),remembered={};
 snapshot.restore(remembered);
 if(!entrySelection&&remembered.month===model.month)Object.assign(model,remembered);
 const operations=bindShipmentOperations(root),fitColumns=bindShipmentColumnLayout(root);
 bindShipmentSidebar(app,root);
 bindShipmentFilters(root,current);
 let generation=0,readController;const saving=new Set();
 const invalidateRead=()=>{readController?.abort();model.loading=false;return ++generation;};
 const endpoint=(id='')=>{
  const query=new URLSearchParams({company:token.company,city:model.city,provider:model.provider,month:model.month,page:model.page});
  if(model.layout==='invoice'&&(!id||id==='search'))query.set('layout','invoice');
  if(query.has('layout')&&shipmentCapabilities(state).canShare)query.set('cargoSlip','1');
  return '/shipments'+(id?'/'+id:'')+'?'+query;
 };
 const search=bindLiveSearch(root,{formSelector:'[data-shipment-search]',fieldName:'query',current,isBusy:()=>model.busy||saving.size||model.exporting||model.editor||model.editMode||model.writing||model.draftChanges?.length});
 const paint=()=>{if(current())search.paint(()=>{Object.assign(model,shipmentCapabilities(state));paintContent(root,shipmentsView(model));applyShipmentCapabilities(root,model);fitColumns();});};
 const paintSelection=()=>{const picker=root.querySelector('[data-shipment-picker]');if(current()&&picker)picker.outerHTML=shipmentSelection(model);};
 const paintTable=()=>{const table=root.querySelector('[data-shipment-table]');if(current()&&table){const opened=operations.capture(),scroll=root.querySelector('.shipment-scroll'),left=scroll?.scrollLeft||0,active=document.activeElement,focus=active?.matches('[data-shipment-status]')?`[data-id="${active.dataset.id}"][data-shipment-status="${active.dataset.shipmentStatus}"]`:null;Object.assign(model,shipmentCapabilities(state));bulk.prune();table.innerHTML=shipmentTable(model);applyShipmentCapabilities(root,model);fitColumns();root.querySelector('.shipment-scroll').scrollLeft=left;operations.restore(opened);if(focus)root.querySelector(focus)?.focus({preventScroll:true});}};
 root.shipmentLayoutBusy=()=>model.editMode||model.exporting||model.writing||saving.size>0;
 root.shipmentLayoutChanged=()=>{
  const layout=selectedLayout();
  if(!current()||layout===model.layout)return;
  operations.close();model.layout=layout;model.page=0;model.selectedIds.clear();
  const scroll=root.querySelector('.shipment-scroll');if(scroll)scroll.scrollLeft=0;
  void refresh({silent:true});
 };
 const presence=bindShipmentPresence({root,model,current,endpoint,refresh,isAdmin:state.user.role==='admin'});
 const session=bindShipmentEditSession({root,model,current,endpoint,paintTable,refresh,presence,companyName:state.companies.find(c=>Number(c.id)===Number(token.company))?.name,invalidate:invalidateRead});
 const editor=bindShipmentEditor({root,model,current,paintTable,invalidate:invalidateRead,session});
 const documents=bindShipmentDocuments({model,company:()=>workspaceExportCompany(state,token.company),current,endpoint,refresh,invalidate:invalidateRead,openUploadPage:(kind,row)=>openShipmentDocumentWorkspace(state,navigate,kind,row)});
 const share=bindShipmentShare({model,company:()=>workspaceExportCompany(state,token.company),current,endpoint,onFileReady:recordOrders});
 const bulk=bindShipmentBulk({root,model,current,endpoint,paintTable,refresh,invalidate:invalidateRead,canLeave:()=>!model.editMode});
 let pickerEvents=null,pickerObserver=null;
 function closePicker({focus=false}={}){
  model.picker=null;pickerEvents?.abort();pickerObserver?.disconnect();pickerEvents=null;pickerObserver=null;paintSelection();
  if(focus)root.querySelector(`[data-shipment-provider="${model.draftProvider}"]`)?.focus({preventScroll:true});
 }
 function openPicker(step){
  pickerEvents?.abort();pickerObserver?.disconnect();model.picker=step;paintSelection();
  pickerEvents=new AbortController();const {signal}=pickerEvents;
  document.addEventListener('pointerdown',event=>{if(!current()||!root.querySelector('[data-shipment-picker]')?.contains(event.target))closePicker();},{signal});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();closePicker({focus:true});}},{signal});
  pickerObserver=new MutationObserver(()=>{if(!current())closePicker();});pickerObserver.observe(document.body,{childList:true,subtree:true});
  root.querySelector('.shipment-picker-panel [data-shipment-month]')?.focus({preventScroll:true});
 }
 async function refresh({silent=false,background=false}={}){
  const blocked=()=>background&&hasTableTextSelection(root);
  if(!current()||blocked()||model.screen!=='details'||!model.month||saving.size||model.editor||model.writing||model.draftChanges.length)return false;const request=invalidateRead();readController=new AbortController();const signal=readController.signal;model.loading=true;model.error=false;if(!silent)paintTable();
  try{const data=model.query||shipmentFilterCount(model.filters)?await api(endpoint('search'),'POST',{query:model.query,filters:model.filters},{signal}):await api(endpoint(),'GET',undefined,{signal});if(!current()||request!==generation||blocked())return;model.data=data;model.page=data.page;markPageUpdated();model.revision=data.revision;snapshot.save(model);}
  catch(error){if(current()&&request===generation&&error.name!=='AbortError'){model.message=error.message;model.error=true;}}
  finally{if(current()&&request===generation){model.loading=false;if(!blocked())paintTable();}}
 }
 async function applySearch(value,filters=model.filters){
  model.query=value.trim();model.searchDraft=model.query;model.filters={...filters};model.filterDraft={...filters};model.page=0;model.data=null;model.message='';
  await refresh();
 }
 root.addEventListener('input',event=>{if(event.target.matches('[data-shipment-query]'))model.searchDraft=event.target.value;if(event.target.matches('[data-shipment-filter]')){model.filterDraft[event.target.dataset.shipmentFilter]=event.target.value;root.querySelector('[data-shipment-filter="dateTo"]').setCustomValidity('');}});
 root.addEventListener('submit',async event=>{
  if(!event.target.matches('[data-shipment-search]'))return;event.preventDefault();
  if(!current()||saving.size||model.exporting||!await editor.canLeave())return;
  const filters=Object.fromEntries([...event.target.querySelectorAll('[data-shipment-filter]')].map(input=>[input.dataset.shipmentFilter,input.value]));
  if(filters.dateFrom&&filters.dateTo&&filters.dateFrom>filters.dateTo){const menu=root.querySelector('[data-shipment-filters]');menu.open=true;const input=root.querySelector('[data-shipment-filter="dateTo"]');input.setCustomValidity(i18nT('Bitiş tarihi başlangıç tarihinden önce olamaz.'));input.reportValidity();return;}
  await applySearch(event.target.querySelector('[data-shipment-query]').value,filters);
 });
 root.addEventListener('click',async event=>{
  const button=event.target.closest('button');if(!button||!current()||model.presenceBlocked)return;
  if(button.dataset.shipmentCopy){
   const row=model.data?.items.find(item=>item.id===Number(button.closest('[data-shipment-row]')?.dataset.shipmentRow));if(!row)return;
   try{await copyShipmentCell(row,button.dataset.shipmentCopy,button.dataset.shipmentCopyValue);if(current()){button.title=i18nT('Kopyalandı');root.querySelector('.shipment-message').textContent=i18nT('Hücredeki bilgi kopyalandı.');}}
   catch{if(current())root.querySelector('.shipment-message').textContent=i18nT('Kopyalama yapılamadı. Tarayıcının pano iznini kontrol edin.');}
   return;
  }
  if(saving.size||model.exporting||model.writing)return;
  if(!model.readOnly&&await session.click(button))return;
  if(!model.readOnly&&await editor.click(button))return;
  if(model.editor)return;
  if(model.editMode&&(button.matches('[data-shipment-status-save],[data-shipment-bulk-status],[data-shipment-print],[data-shipment-export],[data-shipment-document],[data-shipment-share]')||button.closest('[data-shipment-operations]'))){model.message=i18nT('Önce düzenleme işlemlerini kaydedin veya düzenleme modundan çıkın.');paintTable();return;}
  if(!model.readOnly&&await bulk.click(button))return;
  if(!model.readOnly&&await documents.click(button))return;
  if(!model.readOnly&&await share.click(button))return;
  if(button.hasAttribute('data-shipment-status-save')){const id=Number(button.dataset.shipmentStatusSave),row=model.data?.items.find(item=>item.id===id),input=root.querySelector(`[data-shipment-status-select][data-id="${id}"]`);if(row&&input)await saveRow(row,'rowStatus',input.value,button);return;}
  if(button.hasAttribute('data-shipment-invoice')){
   if(!model.canInvoice||model.editMode||!current()||!await editor.canLeave())return;
   const id=Number(button.dataset.shipmentInvoice);
   const ids=model.layout==='invoice'?model.data?.invoiceGroups?.find(group=>group[0]===id)??[id]:[id];
   const rows=ids.map(key=>model.data?.items.find(row=>row.id===key));
   if(rows.some(row=>!row))return;
   const value=!button.hasAttribute('data-invoice-undo');
   if(!await confirmShipmentInvoice(rows[0],value)||!current()||saving.size)return;
   saving.add(id);
   invalidateRead();
   try{
    const result=await api(endpoint(id),'PUT',{field:'invoiced',value,invoiceRows:rows.map(({id,version})=>({id,version}))},{confirmed:true});
    if(!current())return;
    for(const saved of result.items)Object.assign(rows.find(row=>row.id===saved.id),saved);
    model.message=value?i18nT('Fatura düzenlendi.'):i18nT('Fatura işareti geri alındı.');
    paintTable();
   }catch(error){if(current()){model.message=error.message;paintTable();}}
   finally{saving.delete(id);}
   return;
  }
  if(button.hasAttribute('data-cargo-slip')){
   const allowed=()=>current()&&shipmentCapabilities(state).canShare;
   if(!allowed()||model.editMode||model.layout!=='invoice')return;
   const id=Number(button.dataset.cargoSlip);
   const ids=model.data?.invoiceGroups?.find(group=>group[0]===id);
   if(!ids)return;
   const byId=new Map(model.data?.items.map(row=>[row.id,row]));
   const rows=ids.map(key=>byId.get(key));
   const company=workspaceExportCompany(state,token.company);
   if(!company||rows.some(row=>!row||row.draft||(row.customerReadOnly&&!model.showCancellations)))return;
   openDocumentPrint({current:allowed,formats:['pdf'],directPdf:true,autoPrint:true,allowSave:false,createFile:async()=>{
    const {createShipmentCargoSlip}=await import('./shipment-cargo-slip.js');
    return createShipmentCargoSlip(rows,company);
   }});
   return;
  }
  if(button.hasAttribute('data-shipment-print')){
   if(!model.canShare)return;const selection={query:model.query,filters:{...model.filters}},url=endpoint('export');let snapshot;
   openDocumentPrint({current,directPdf:true,allowSave:false,createFile:async type=>{
    const data=await(snapshot??=api(url,'POST',selection).catch(error=>{snapshot=null;throw error;}));
    const {createShipmentPdf,createShipmentWorkbook,shipmentExportFilename}=await import('./shipment-export.js');
    const file={blob:await(type==='xlsx'?createShipmentWorkbook(data):createShipmentPdf(data)),filename:shipmentExportFilename(data,type)};
    if(current())recordOrders?.(file);
    return file;
   }});return;
  }
  if(button.dataset.shipmentExport&&model.canShare&&!model.readOnly){
   const request=++generation;model.exporting=true;model.message=i18nT('Dosya hazırlanıyor…');paintTable();
   try{
    const filename=documentFilename({company:state.companies.find(company=>Number(company.id)===Number(token.company)),city:model.city,provider:model.provider,kind:'shipment',type:button.dataset.shipmentExport});
    const destination=await prepareReportSave(filename);if(destination==='cancelled'){model.message=downloadStatus('cancelled');return;}
    const data=await api(endpoint('export'),'POST',{query:model.query,filters:model.filters});const {downloadShipments}=await import('./shipment-export.js');
    if(current()&&request===generation)model.message=await downloadShipments(data,button.dataset.shipmentExport,()=>current()&&request===generation,destination,filename,recordOrders);
   }
   catch(error){if(current()&&request===generation)model.message=error.message;}
   finally{model.exporting=false;if(current()&&request===generation)paintTable();}return;
  }
  if(button.hasAttribute('data-shipment-filter-reset')){if(!await editor.canLeave())return;await applySearch(model.query,emptyShipmentFilters());return;}
  if(button.hasAttribute('data-shipment-clear')){if(!await editor.canLeave())return;await applySearch('');return;}
  if(button.hasAttribute('data-shipment-close')){closePicker({focus:true});return;}
  if(button.dataset.shipmentProvider){
   if(!await editor.canLeave())return;
   const provider=button.dataset.shipmentProvider;
   Object.assign(model,{provider,draftProvider:provider,city:'all',page:0,data:null});
   model.message='';model.selectedIds?.clear();closePicker();paint();await refresh();return;
  }
  if(button.hasAttribute('data-shipment-months')){model.draftProvider=model.provider;openPicker('months');return;}
  if(button.hasAttribute('data-shipment-refresh')){try{await root.pageRefresh();}catch(error){model.message=error.message;paintTable();}return;}
  const pageChanged=button.hasAttribute('data-shipment-page');
  if(pageChanged){if(button.disabled||model.busy||!await editor.canLeave())return;model.page=Number(button.dataset.shipmentPage);}
  else if(button.dataset.shipmentMonth){if(!await editor.canLeave())return;model.city='all';model.provider=model.draftProvider;model.month=button.dataset.shipmentMonth;model.page=0;model.query='';model.searchDraft='';model.filters=emptyShipmentFilters();model.filterDraft=emptyShipmentFilters();closePicker();}
  else return;
  model.data=null;model.message='';paint();await refresh();
  if(pageChanged&&current()&&!model.error)scrollTableStart(root,'.shipment-scroll');
 });
 root.addEventListener('change',async event=>{
  if(event.target.matches('[data-shipment-provider-filter]')&&current()){
   if(saving.size||model.exporting||!await editor.canLeave()){event.target.value=model.provider;return;}
   const provider=event.target.value;
   Object.assign(model,{provider,draftProvider:provider,page:0,data:null});
   model.selectedIds?.clear();model.message='';model.addWarning='';paint();await refresh();return;
  }
  if(event.target.matches('[data-shipment-month-filter]')&&current()){
   if(saving.size||model.exporting||!await editor.canLeave()){event.target.value=model.month;return;}
   model.month=event.target.value;model.page=0;model.query='';model.searchDraft='';model.filters=emptyShipmentFilters();model.filterDraft=emptyShipmentFilters();model.data=null;model.message='';paint();await refresh();return;
  }
  if(event.target.matches('[data-shipment-city-filter]')&&current()){
   if(saving.size||model.exporting||!await editor.canLeave()){event.target.value=model.city;return;}
   const city=event.target.value;
   Object.assign(model,{city,page:0,data:null});
   model.selectedIds?.clear();model.message='';paint();await refresh();return;
  }
  const input=event.target;if(model.readOnly||!input.matches('[data-shipment-check]')||!current())return;
  const id=Number(input.dataset.id),row=model.data?.items.find(item=>item.id===id);if(!row)return;
  if(saving.size||model.exporting||model.editMode||!await editor.canLeave()){input.checked=row[input.dataset.shipmentCheck];return;}
  input.checked=row[input.dataset.shipmentCheck];
  await saveRow(row,input.dataset.shipmentCheck,!row[input.dataset.shipmentCheck],input);
 });
 async function saveRow(row,field,value,control){
  if(model.readOnly||(field==='invoiced'?!model.canInvoice:!model.canConfirm))return;
  const id=row.id,element=control.closest('tr'),request=invalidateRead();
  const focusSelector=control.hasAttribute('data-shipment-status-save')?`[data-shipment-status-select][data-id="${id}"]`:`[data-id="${id}"][data-shipment-check="${field}"]`;
  model.busy=false;saving.add(id);root.querySelectorAll('[data-shipment-check],[data-shipment-status-select],[data-shipment-status-save],[data-shipment-select],[data-shipment-select-all],[data-shipment-bulk-status]').forEach(input=>input.disabled=true);
  try{
   let confirmed={version:row.version};
   if(field==='rowStatus'){
    const preview=await confirmShipmentStatus({endpoint,rows:[row],value,current});
    if(!preview){model.message=i18nT('Durum değişikliği yapılmadı.');root.querySelector('.shipment-message').textContent=model.message;return;}
    confirmed={version:preview.items[0].version,confirmation:preview.items[0].confirmation};
   }
   const result=await api(endpoint(id),'PUT',{field,value,...confirmed});
   if(!current()||request!==generation)return;
   const reopenMenu=element.querySelector('[data-shipment-operations][open]')?operations.capture():null;
   Object.assign(row,result);
   element.outerHTML=shipmentRow(row,model.selectedIds.has(row.id),model.canConfirm,false,model.editMode,model.readOnly,model.layout,[row],model.canSetCancellation,model.canInvoice,model.canShare);
   if(model.data.undo?.id===id)model.data.undo.version=row.version;
   applyShipmentCapabilities(root,model);fitColumns();
   operations.restore(reopenMenu);
   root.querySelector(focusSelector)?.focus({preventScroll:true});
   model.message=i18nT('Kaydedildi.');root.querySelector('.shipment-message').textContent=model.message;
   if(model.filters[field]||(field==='rowStatus'&&(model.filters.cancelled||model.filters.shipped))){saving.delete(id);await refresh();}
  }catch(error){if(current()&&request===generation){model.message=error.message;root.querySelector('.shipment-message').textContent=error.message;if(error.status===409){saving.delete(id);await refresh();}}}
  finally{saving.delete(id);if(current())root.querySelectorAll('[data-shipment-check],[data-shipment-status-select],[data-shipment-status-save],[data-shipment-select],[data-shipment-select-all],[data-shipment-bulk-status]').forEach(input=>input.disabled=false);}
 }
 const canRefresh=()=>model.screen==='details'&&!model.editMode&&!model.editor&&!model.writing&&!model.picker&&!model.loading&&!model.busy&&!model.exporting&&!saving.size&&!hasTableTextSelection(root)&&!root.querySelector('details:is([data-shipment-operations],[data-shipment-product-name])[open]')&&!root.querySelector('[data-shipment-filters][open]')&&!document.querySelector('dialog[open]')&&model.searchDraft===model.query&&!(root.contains(document.activeElement)&&document.activeElement.matches('input,select,textarea'));
 bindShipmentSync({root,model,current,endpoint,canRefresh,refresh});
 // The existing 30-second refresh also reconciles restores and audit commits that arrive out of order.
 root.pageRefresh=async()=>{if(model.editor||model.draftChanges.length||model.writing)throw new Error(i18nT('Taslak değişiklikler korunuyor. Önce Kaydet veya Düzenlemeyi bitir seçeneğini kullanın.'));if(model.busy||model.exporting||saving.size)throw new Error(i18nT('Devam eden işlemin tamamlanmasını bekleyin.'));await refresh({silent:true});if(model.error)throw new Error(model.message);};
 root.shipmentRefresh=()=>canRefresh()?refresh({silent:true,background:true}):Promise.resolve();
 paint();void refresh();
}
