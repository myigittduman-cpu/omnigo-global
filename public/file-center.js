import {api} from './api.js';
import {html,t,text} from './i18n.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {loadingState,contentError} from './content-state.js';
import {latestPageRead} from './latest-page-read.js';
import {paintContent} from './content-paint.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {scrollTableStart} from './table-page-scroll.js';
import {markPageUpdated} from './page-refresh.js';
import {downloadStatus,prepareReportSave,saveReportFile} from './report-download.js';
import {fileCenterRecorder,archiveUpdateEvent,downloadArchivedFile,readArchivedFile} from './file-center-archive.js';
import {fileCenterProducts,fileCenterArchive} from './file-center-view.js';
import {bindFileCenterSelection} from './file-center-selection.js';
import {confirmAction} from './action-confirmation.js';
import {shareFileCenterProducts} from './file-center-share.js';
import {documentOutputActions} from './document-output-actions.js';
import {openPreparedPdfShare} from './upcoming-payment-share.js';
import {openDocumentPrint} from './document-print.js';
import {now} from './ui.js';
import {inventorySortOptions} from './inventory-sorting.js';
import {fileCenterPdfFilename,fileCenterStockFilename} from './file-center-export-name.js';
import {workspaceExportCompany} from './workspace-export-company.js';

if(typeof document!=='undefined'){
 const style=document.createElement('link');
 style.rel='stylesheet';style.href='/file-center.css';
 document.head.append(style);
}

export function bindFileCenter(app,state){
 const root=app.querySelector('[data-file-center]');
 if(!root||state.demo||state.user?.role!=='admin')return;
 const token=workspaceToken(state);
 const current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='fileCenter';
 const selectionScope=state.data?.workspaceAccess?.setupOptions;
 const productsEnabled=!selectionScope||selectionScope.live.includes('fileCenter');
 const views=productsEnabled?['products','archive']:['archive'];
 const model={fileCenterTab:views[0],page:0,query:'',brand:'',sort:'stable',data:null,loading:false,busy:false};
 const snapshot=pageSnapshot(state,'fileCenter',['fileCenterTab','page','query','brand','sort','data']);
 snapshot.restore(model);
 if(!views.includes(model.fileCenterTab))Object.assign(model,{fileCenterTab:views[0],page:0,query:'',data:null});
 const read=latestPageRead(model,current);
 const isArchive=()=>model.fileCenterTab==='archive';
 const company=()=>workspaceExportCompany(state,token.company);
 root.innerHTML=html`
  <nav class="file-center-tabs" aria-label="Dosya Merkezi">
   ${productsEnabled?html`<button type="button" class="btn" data-file-center-view="products" aria-pressed="true">Pazaryeri Ürünleri</button>`:''}
   <button type="button" class="btn" data-file-center-view="archive" aria-pressed="false">Hazırlanan Dosyalar</button>
  </nav>
  <div class="file-center-toolbar">
   <form data-file-center-search role="search">
    <input type="search" name="query" aria-label="Ürün ara" placeholder="Ürün adı, stok kodu veya barkod" autocomplete="off" maxlength="120">
    <button type="submit" class="btn">Ara</button>
    <div class="file-center-filters">
     <select name="brand" aria-label="Marka filtresi"><option value="">Tüm markalar</option></select>
     <select name="sort" aria-label="Ürün sıralaması">
      ${['sku','name_asc','name_desc'].map(value=>html`<option value="${value}">${t(inventorySortOptions[value])}</option>`).join('')}
     </select>
    </div>
   </form>
   <div class="file-center-actions">
    <span data-file-center-selection-count role="status"></span>
    <button type="button" class="btn" data-file-center-remove disabled>Listeden sil</button>
    <button type="button" class="btn" data-file-center-export disabled>Bu sayfayı Excel indir</button>
    ${documentOutputActions({pdf:{attr:'data-file-center-pdf',disabled:true},share:{attr:'data-file-center-share',disabled:true},print:{attr:'data-file-center-print',disabled:true}})}
   </div>
  </div>
  <p class="file-center-feedback" data-file-center-message role="status"></p>
  <div data-file-center-result>${loadingState('Ürünler yükleniyor…',{compact:true})}</div>`;
 const table=root.querySelector('[data-file-center-result]');
 const search=root.querySelector('form');
 const {brand,sort}=search.elements;
 const exportButton=root.querySelector('[data-file-center-export]');
 const shareButton=root.querySelector('[data-file-center-share]');
 const pdfButton=root.querySelector('[data-file-center-pdf]');
 const printButton=root.querySelector('[data-file-center-print]');
 const removeButton=root.querySelector('[data-file-center-remove]');
 const notice=root.querySelector('[data-file-center-message]');
 const recordStock=fileCenterRecorder(state,token,'stock');
 const selection=bindFileCenterSelection(root,current);
 const selectionState=()=>{
  const ready=!model.busy&&Boolean(model.data?.items.length);
  brand.disabled=sort.disabled=model.busy;
  return {ready,onChange:count=>{
   removeButton.disabled=!ready||!count;
   exportButton.disabled=shareButton.disabled=printButton.disabled=pdfButton.disabled=!ready||isArchive();
  }};
 };
 const updateSelection=()=>selection.update(selectionState());
 selection.bind(selectionState);

 function applyTab(){
  for(const element of root.querySelectorAll('[data-file-center-view]')){
   element.setAttribute('aria-pressed',String(element.dataset.fileCenterView===model.fileCenterTab));
  }
  search.hidden=exportButton.hidden=pdfButton.closest('.document-output-actions').hidden=isArchive();
 }
 function renderTable(){
  if(!isArchive()){
   const options=[{value:'',label:t('Tüm markalar')},...(model.data.brands??[])];
   if(model.brand&&!options.some(option=>option.value===model.brand)){
    options.push({value:model.brand,label:brand.selectedOptions[0]?.value===model.brand
     ?brand.selectedOptions[0].text:t('Seçili marka')});
   }
   if(options.length!==brand.options.length||options.some((option,index)=>
    option.value!==brand.options[index].value||t(option.label)!==brand.options[index].text)){
    brand.replaceChildren(...options.map(option=>new Option(t(option.label),option.value)));
   }
   brand.value=model.brand;
  }
  paintContent(table,isArchive()?fileCenterArchive(model.data):fileCenterProducts(model.data));
  updateSelection();
 }
 applyTab();
 search.elements.query.value=model.query;
 sort.value=model.sort;
 if(model.data)renderTable();

 async function refresh({quiet=false,scroll=false,afterRemoval=false,manual=false}={}){
  if(!current()||(quiet&&model.loading))return false;
  if(model.busy&&!afterRemoval){
   if(manual)throw new Error(t('Devam eden dosya işleminin tamamlanmasını bekleyin.'));
   return false;
  }
  const archive=isArchive(),request=read.begin();
  if(!quiet){
   notice.textContent=t(archive?'Dosyalar yükleniyor…':'Ürünler yükleniyor…');
   table.setAttribute('aria-busy','true');
  }
  updateSelection();
  try{
   const suffix=new URLSearchParams({company:token.company,page:model.page,...(archive?{}:{
    query:model.query,brand:model.brand,sort:model.sort,
   })});
   const data=await api('/file-center/'+model.fileCenterTab+'?'+suffix,'GET',undefined,{signal:request.signal});
   if(!request.active())return false;
   model.data=data;
   model.page=data.page;
   renderTable();
   snapshot.save(model);
   markPageUpdated();
   if(!quiet)notice.textContent='';
   if(scroll)scrollTableStart(root,'.file-center-table');
   return true;
  }catch(error){
   if(!request.active())return false;
   notice.textContent=error.message;
   if(!model.data)paintContent(table,contentError(error.message));
   if(manual)throw error;
   return false;
  }finally{
   if(request.finish()){
    table.removeAttribute('aria-busy');
    updateSelection();
   }
  }
 }

 async function fileAction(action){
  model.busy=true;
  updateSelection();
  notice.textContent=t('Dosya hazırlanıyor…');
  try{
   const result=await action();
   if(current())notice.textContent=downloadStatus(result);
  }catch(error){if(current())notice.textContent=error.message;}
  finally{model.busy=false;if(current())updateSelection();}
 }

 async function removeRows(){
  const selected=selection.selected(model.data.items);
  if(!selected.length)return;
  const archive=isArchive(),target=model.fileCenterTab;
  const message=archive
   ?text`${selected.length} dosya arşiv listesinden kaldırılacak.`
   :text`${selected.length} ürün Dosya Merkezi’nden kaldırılacak. Stok Yönetimi’ndeki ürünler korunacak.`;
  read.cancel();
  model.busy=true;
  updateSelection();
  let reload=false;
  try{
   const confirmed=await confirmAction(message,{
    heading:t('Listeden sil'),approveLabel:t('Listeden sil'),scope:company()?.name||'',
   });
   if(!confirmed||!current())return;
   const {removed}=await api('/file-center/'+target+'/selection?'+new URLSearchParams({company:token.company}),
    'DELETE',{items:selected.map(item=>item.key??item.id)},{confirmed:true});
   if(!current())return;
   selection.clear();
   notice.textContent=removed===0
    ?t('Seçili kayıtlar zaten listeden kaldırılmış.')
    :archive?text`${removed} dosya arşiv listesinden kaldırıldı.`:text`${removed} ürün listeden kaldırıldı.`;
   reload=true;
  }catch(error){
   if(!current())return;
   notice.textContent=error.message;
   reload=error.status===409;
   if(reload)selection.clear();
  }
  finally{
   if(reload)await refresh({quiet:true,afterRemoval:true});
   model.busy=false;
   if(current()){
    table.removeAttribute('aria-busy');
    updateSelection();
   }
  }
 }

 function resetForRead(){
  selection.clear();
  model.data=null;
  updateSelection();
 }
 function applyFilters({scroll=false}={}){
  if(!current()||model.busy||isArchive())return;
  model.query=search.elements.query.value.trim();
  model.brand=brand.value;
  model.sort=sort.value;
  model.page=0;
  resetForRead();
  void refresh({scroll});
 }
 brand.onchange=sort.onchange=()=>applyFilters();
 search.addEventListener('submit',event=>{
  event.preventDefault();
  applyFilters({scroll:true});
 });
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button||!current()||model.busy||button.disabled)return;
  const view=button.dataset.fileCenterView;
  if(views.includes(view)&&view!==model.fileCenterTab){
   Object.assign(model,{fileCenterTab:view,page:0});
   resetForRead();
   applyTab();
   void refresh();
  }else if(button.hasAttribute('data-file-center-page')&&!model.loading){
   const page=Number(button.dataset.fileCenterPage);
   if(!Number.isSafeInteger(page)||page<0||page===model.page)return;
   model.page=page;
   resetForRead();
   void refresh({scroll:true});
  }else if(isArchive()&&!model.loading&&button.matches('[data-file-center-download],[data-archive-share],[data-archive-print]')){
   const id=Number(button.dataset.fileCenterDownload||button.dataset.archiveShare||button.dataset.archivePrint);
   const file=model.data?.items.find(item=>item.id===id);
   if(!file)return;
   const active=()=>current()&&isArchive();
   if(button.hasAttribute('data-file-center-download'))void fileAction(()=>downloadArchivedFile(file,token.company,active));
   else if(file.type==='pdf'){
    const createFile=()=>readArchivedFile(file,token.company);
    if(button.hasAttribute('data-archive-print'))openDocumentPrint({current:active,formats:['pdf'],directPdf:true,createFile});
    else openPreparedPdfShare({title:t('WhatsApp ile PDF paylaş'),description:file.name,current:active,createFile});
   }
  }else if(button===removeButton){
   void removeRows();
  }else if([pdfButton,shareButton,printButton].includes(button)&&!isArchive()&&model.data?.items.length){
   const selected=selection.selected(model.data.items);
   const data=selected.length?{...model.data,items:selected}:model.data;
   const brand=company(),date=now(),active=()=>current()&&!isArchive();
   if(button===shareButton){shareFileCenterProducts(data,brand,active,recordStock);return;}
   const createFile=async()=>{
    const {createFileCenterPdf}=await import('./file-center-pdf.js');
    const file=await createFileCenterPdf(data,brand,date);
    if(active())recordStock?.(file);
    return file;
   };
   if(button===printButton)openDocumentPrint({current:active,formats:['pdf'],directPdf:true,createFile});
   else void fileAction(async()=>{
    const destination=await prepareReportSave(fileCenterPdfFilename(brand,date));
    if(destination==='cancelled'||!active())return destination||'stale';
    const file=await createFile();
    return saveReportFile(file.blob,file.filename,active,globalThis,destination,{browserDownload:true});
   });
  }else if(button===exportButton&&model.data?.items.length){
   const data=model.data;
   void fileAction(async()=>{
    const brand=company(),createdAt=new Date();
    const filename=fileCenterStockFilename(data,brand,createdAt);
    const destination=await prepareReportSave(filename);
    if(destination==='cancelled'||!current())return destination||'stale';
    const {createFileCenterStockFile}=await import('./file-center-stock-export.js');
    const file=await createFileCenterStockFile(data,brand,createdAt);
    if(!current())return 'stale';
    recordStock?.(file);
    return saveReportFile(file.blob,file.filename,current,globalThis,destination,{browserDownload:true});
   });
  }
 });
 root.pageRefresh=()=>refresh({manual:true});
 root.fileCenterRefresh=()=>refresh({quiet:true});
 const scope=new AbortController();
 window.addEventListener(archiveUpdateEvent,event=>{
  if(current()&&isArchive()&&event.detail.user===token.user&&event.detail.company===token.company){
   void refresh({quiet:true});
  }
 },{signal:scope.signal});
 const removal=new MutationObserver(()=>{
  if(root.isConnected)return;
  scope.abort();
  read.cancel();
  removal.disconnect();
 });
 removal.observe(app,{childList:true,subtree:true});
 void refresh();
}
