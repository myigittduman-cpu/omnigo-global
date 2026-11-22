import {inventoryCapabilities} from './inventory-capabilities.js';
import {openMarketplaceStock} from './inventory-marketplace-stock.js';
import {inventorySortName} from './inventory-sorting.js';
import {latestPageRead} from './latest-page-read.js';
import {paintContent} from './content-paint.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {inventoryPageSize} from './inventory-pagination.js';
import {copyInventorySku} from './inventory-copy.js';
import {inventoryStockForm,stockPreview,parseStockAmount,marketplaceStockAmount} from './inventory-stock-form.js';
import {openInventoryHistory} from './inventory-history.js';
import {scrollInventoryTable} from './inventory-page-scroll.js';
import {bindLiveSearch} from './live-search.js';
import {openInventorySalesDetails} from './inventory-sales-details.js';
import {inventoryRetryVisible,inventoryErrorSources} from './inventory-retry-state.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {showFeedback} from './save-feedback.js';
import {t} from './i18n.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {markPageUpdated} from './page-refresh.js';
import {openMarketplaceStockDetails} from './inventory-stock-details.js';
import {inventoryView} from './inventory-view.js';
import {openManualProduct,openProductMatching} from './inventory-manual.js';
import {openInventoryImage} from './inventory-images.js';
import {openInventoryPurchasePrice} from './inventory-purchase-price.js';
import {canRefreshInventory,inventoryRefreshDelay} from './inventory-refresh-policy.js';
import {openInventoryImportReport} from './inventory-import-report.js';
import {mountInventoryImportHeading,openInventoryImportDashboard} from './inventory-import-dashboard.js';

for(const file of ['inventory.css','inventory-mobile.css']){const style=document.createElement('link');style.rel='stylesheet';style.href=new URL(file,import.meta.url).href;document.head.append(style);}
export const inventoryContent=()=>'<section data-inventory aria-label="Stok Yönetimi"></section>';
export function bindInventory(app,state){
 const root=app.querySelector('[data-inventory]');if(!root)return;
 const token=workspaceToken(state),current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='inventory';
 const model={admin:state.user.role==='admin',query:'',filter:'stocked',provider:'',category:'',sort:'stable',page:0,pageSize:10,data:null,error:'',busy:false,selected:new Set()};
 root.addEventListener('click',async event=>{
  const trigger=event.target.closest('[data-conflict-records]');
  if(!trigger||trigger.disabled||!current())return;
  trigger.disabled=true;
  try{
   const result=await api(endpoint('?detail=sku-conflicts'));
   if(current())openDialog('Stok kodu farklı ürünlerle eşleşen kayıtlar',result.items.length
    ? '<ul>'+result.items.map(item=>'<li><strong>'+esc(item.sku)+'</strong> · '+esc(item.title)+'</li>').join('')+'</ul>'
    : '<p>Gösterilecek güncel kayıt yok.</p>');
  }catch(error){if(current())toast(error.message);}
  finally{trigger.disabled=false;}
 });
 const reads=latestPageRead(model,current);
 let importDashboard=null;
 const snapshot=pageSnapshot(state,'inventory',["data","query","filter","provider","category","sort","page","pageSize"]);snapshot.restore(model);
 const endpoint=path=>'/inventory'+path+(path.includes('?')?'&':'?')+new URLSearchParams({company:token.company});
 const search=bindLiveSearch(root,{formSelector:'[data-inventory-search]',fieldName:'query',current,isBusy:()=>model.busy});
 const importHeading=mountInventoryImportHeading(root,{admin:model.admin,onOpen:()=>{if(!model.admin||!current())return;void openInventoryImportDashboard({state,token,current,getData:()=>model.data,read:signal=>api(endpoint('?'+new URLSearchParams({query:model.query,page:model.page,pageSize:model.pageSize,filter:model.filter,provider:model.provider,category:model.category,sort:inventorySortName(model.sort)})),'GET',undefined,{signal}),onData:data=>{if(current()&&model.data){model.data.connectionImports=data?.connectionImports||[];model.data.metadata=data?.metadata;for(const key of ['progress','initialImport'])if(data?.[key]!==undefined)model.data[key]=data[key];snapshot.save(model);paint();}}}).then(controller=>{importDashboard?.dispose();importDashboard=controller;});}});
 const permissions=()=>inventoryCapabilities(state,model.data?.capabilities);
 const priceForm=()=>root.querySelector('[data-purchase-price-form]');
 function protectPriceDraft(event){
  const form=priceForm();
  if(!form||form.contains(event.target)||!event.target.closest('button,input,select,[data-inventory-search]'))return;
  if(form.canLeaveInlineEdit())form.closeInlineEdit();
  else{event.preventDefault();event.stopImmediatePropagation();}
 }
 for(const type of ['click','submit','change'])root.addEventListener(type,protectPriceDraft,true);
 const paint=()=>{model.capabilities=permissions();if(current()){importHeading.paint(model.data);if(!priceForm())search.paint(()=>{paintContent(root,inventoryView(model));});}};
 async function refresh({scrollToTable=false,quiet=false}={}){if(!current()||model.busy)return false;let loaded=false;model.selected.clear();const request=reads.begin();model.error='';if(!quiet)paint();try{const result=await api(endpoint('?'+new URLSearchParams({query:model.query,page:model.page,pageSize:model.pageSize,filter:model.filter,provider:model.provider,category:model.category,sort:inventorySortName(model.sort)})),'GET',undefined,{signal:request.signal});if(request.active()){model.data={...result,persistentError:inventoryRetryVisible(token.company,inventoryErrorSources(result))};model.page=result.page;model.pageSize=inventoryPageSize(result.pageSize);markPageUpdated();snapshot.save(model);loaded=true;}}catch(error){if(request.active()&&error.name!=='AbortError')model.error=error.message;}finally{if(request.finish()){paint();if(loaded&&scrollToTable)scrollInventoryTable(root);}}return loaded;}
 root.addEventListener('submit',event=>{if(!event.target.matches('[data-inventory-search]'))return;event.preventDefault();model.query=event.target.elements.query.value;model.filter=event.target.elements.filter.value;model.provider=event.target.elements.provider.value;model.category=event.target.elements.category.value;model.sort=inventorySortName(event.target.elements.sort.value);model.page=0;void refresh();});
 root.addEventListener('change',event=>{
  const input=event.target;
  if(input.matches('[data-stock-select],[data-stock-select-page]')){
   if(!model.admin||model.busy)return;
   const keys=input.hasAttribute('data-stock-select-page')?model.data.items.filter(x=>!x.pending).map(x=>x.key):[input.dataset.stockSelect];
   for(const key of keys)input.checked?model.selected.add(key):model.selected.delete(key);
   paint();return;
  }
  if(input.matches('[data-inventory-page-size]')){
   if(model.busy)return;
   model.pageSize=inventoryPageSize(input.value);model.page=0;
   model.query=root.querySelector('[name=query]').value;void refresh({scrollToTable:true});return;
  }
  if(!['filter','provider','category','sort'].includes(input.name))return;model[input.name]=input.value;model.query=root.querySelector('[name=query]').value;model.page=0;void refresh();
 });
 root.addEventListener('click',event=>{const button=event.target.closest('button');if(!button)return;
  if(button.hasAttribute('data-inventory-import-report')){if(!model.admin||!current())return;const run=model.data?.connectionImports?.find(item=>String(item.runId)===button.dataset.inventoryImportReport);if(run)void openInventoryImportReport(run,state,token);return;}
  if(button.hasAttribute('data-stock-copy')){void copyInventorySku(button);return;}
  if(button.hasAttribute('data-stock-history-all')){if(!permissions().viewMovements)return;openInventoryHistory({endpoint,current});return;}
  if(button.hasAttribute('data-stock-bulk')){void visibility(model.data.items.filter(x=>model.selected.has(x.key)),model.filter==='removed'?'restore':'remove');return;}
  if(button.hasAttribute('data-inventory-start')){void start();return;}
  if(button.hasAttribute('data-manual-add')){openManualProduct({endpoint,current,refresh,providers:model.data.providers,online:model.data.settings.mode!=='store',horoz:model.data.settings.mode==='horoz'});return;}
  if(button.hasAttribute('data-pending-product')){const pending=model.data?.pendingProducts?.find(x=>x.key===button.dataset.pendingProduct);if(pending){model.query=pending.sku||pending.title;model.filter=model.data.skuOnly||model.data.barcodeOnly?'legacy':'all';model.provider='';model.category='';model.page=0;void refresh();}return;}
  if(button.hasAttribute('data-inventory-page')){if(model.busy||button.disabled)return;model.page=Number(button.dataset.inventoryPage);void refresh({scrollToTable:true});return;}
  const key=button.dataset.marketplaceStock||button.dataset.purchasePrice||button.dataset.stockSales||button.dataset.stockSources||button.dataset.stockImage||button.dataset.stockRemove||button.dataset.stockRestore||button.dataset.storeSale||button.dataset.stockEdit||button.dataset.stockHistory||button.dataset.stockMatching,item=model.data?.items.find(x=>x.key===key);if(!item)return;
  if(button.hasAttribute('data-marketplace-stock')){if(model.admin&&model.data?.marketplaceManagement)openMarketplaceStock(item,{endpoint,current,refresh});return;}
  if(button.hasAttribute('data-purchase-price')){
   const canEdit=()=>current()&&permissions().editPurchasePrice&&permissions().viewPurchasePrice&&!item.removed;
   if(!canEdit()||model.busy||priceForm())return;
   reads.cancel();
   openInventoryPurchasePrice(item,{button,endpoint,current,refresh,canEdit,
    onSaved:result=>{
     Object.assign(item,result);
     snapshot.save(model);paint();void refresh({quiet:true});
    },
    onClose:()=>{paint();void refresh({quiet:true});}
   });
   return;
  }
  if(button.hasAttribute('data-stock-sales')){if(!permissions().viewSales)return;openInventorySalesDetails(item);return;}
  if(button.hasAttribute('data-stock-sources')){if(!permissions().viewMarketplaceStocks)return;openMarketplaceStockDetails(item);return;}
  if(button.hasAttribute('data-stock-image')){openInventoryImage(item,{admin:model.admin,endpoint,current,refresh});return;}
  if(button.hasAttribute('data-stock-remove')||button.hasAttribute('data-stock-restore')){void visibility(item,button.hasAttribute('data-stock-restore')?'restore':'remove');return;}
  if(button.hasAttribute('data-store-sale'))edit(item,true);else if(button.hasAttribute('data-stock-edit'))edit(item);else if(button.hasAttribute('data-stock-matching'))openProductMatching(item,{endpoint,current,refresh,providers:model.data.providers});else if(permissions().viewMovements)openInventoryHistory({item,endpoint,current});
 });
 async function visibility(selection,action){
  const items=Array.isArray(selection)?selection:[selection];
  if(model.busy||!current()||!model.admin||!items.length)return;reads.cancel();model.busy=true;model.error='';paint();
  try{await api(endpoint('/visibility'),'POST',{products:items.map(x=>({productKey:x.key,version:x.version})),action});}catch(error){model.error=error.message;}finally{model.busy=false;paint();}
  if(!model.error)await refresh();
 }
 async function start(){
  if(!current()||model.busy||!model.data?.activation?.canStart)return;
  const activation=model.data.activation;
  reads.cancel();model.busy=true;model.error='';paint();
  try{
   const result=await api(endpoint('/start'),'POST',{profileVersion:activation.profileVersion});
   if(!current())return;
   if(!result.marketplaceActive)throw new Error(t('Canlı stok aktarımı başlatılamadı.'));
   showFeedback(t(activation.marketplaceActive?'Stok aktarımı için yeniden kontrol istendi.':'Canlı stok aktarımı başlatıldı.'),{kind:'pending'});
  }catch(error){if(current()&&!error.cancelled){model.error=error.message;showFeedback(error.message,{kind:'error'});}}
  finally{model.busy=false;paint();}
  if(!model.error)await refresh();
 }
 // A form is bound to the tenant captured when it was opened; navigation cannot reassign its writes.
 function edit(item,storeSale=false){
  if(!permissions()[storeSale?'recordStoreSale':'editStock'])return;
  const first=item.quantity===null,horoz=model.data.settings.mode==='horoz';
  const title=storeSale?'Mağaza satışı kaydet':horoz?'Ortak stoğu düzenle':first?'Mağaza stoğu gir':'Mağaza stoğunu düzenle';
  const dialog=openDialog(title,inventoryStockForm(item,storeSale,{horoz,canReturn:model.admin}),{className:'inventory-dialog'});
  const form=dialog.querySelector('[data-stock-form]'),message=form.querySelector('[data-stock-result]');let busy=false,requestId=crypto.randomUUID();
  form.noValidate=true;
  const preview=()=>{form.querySelector('[data-stock-preview]').textContent=stockPreview(item,form.elements.amount.value,storeSale,{horoz,mode:form.elements.mode.value});};
  form.elements.amount.oninput=preview;form.elements.mode.onchange=preview;
  form.querySelector('[data-stock-use-marketplace]')?.addEventListener('click',()=>{const amount=marketplaceStockAmount(item);if(amount===null||busy)return;form.elements.amount.value=String(amount);preview();form.elements.amount.focus({preventScroll:true});});
  form.onsubmit=async event=>{event.preventDefault();if(busy||!current())return false;busy=true;form.querySelector('.form-error').textContent='';const button=form.querySelector('[type=submit]');button.disabled=true;try{const amount=parseStockAmount(form.elements.amount.value,storeSale);const result=await api(endpoint('/commands'),'POST',{productKey:item.key,requestId,version:item.version,mode:form.elements.mode.value,amount,...(horoz?{sourceVersion:item.sourceVersion}:{})});if(!current()||!dialog.open)return;if(result.state==='done'){form.reset();dialog.close();toast('Bilgileriniz kaydedildi.');await refresh();return true;}if(result.state==='failed')throw new Error(result.result?.error||'Stok kaydı tamamlanamadı.');message.textContent='Stok kaydı sıraya alındı. Sunucu kaydı tamamlayacak.';form.querySelectorAll('input,select,button').forEach(x=>x.disabled=true);await refresh();void poll(result.requestId,dialog,message,form);}catch(error){form.querySelector('.form-error').textContent=error.message;button.disabled=false;return false;}finally{busy=false;}};
 }
 async function poll(requestId,dialog,message,form){
  for(let attempt=0;attempt<36&&current()&&dialog.open;attempt++){
   await new Promise(resolve=>setTimeout(resolve,750));if(!current()||!dialog.open)return;
   try{const result=await api(endpoint('/commands?'+new URLSearchParams({requestId})));if(result.state==='pending')continue;
    if(result.state==='done'){form.reset();dialog.close();toast('Bilgileriniz kaydedildi.');await refresh();return true;}
    message.textContent=result.result?.error||'Stok kaydı tamamlanamadı. Güncel bilgileri kontrol edin.';await refresh();return;
   }catch(error){message.textContent=error.message;return;}
  }
  if(current()&&dialog.open)message.textContent='İşlem sunucuda sürüyor. Tamamlandığında stok tablosuna yansıyacak.';
 }
 // Automatic refresh does not discard a search draft or an open count form.
 root.inventoryRefresh=()=>{if(priceForm()||!canRefreshInventory(model,{dialogOpen:!!document.querySelector('dialog[open]'),focusedTag:root.contains(document.activeElement)?document.activeElement.tagName:'',draftQuery:root.querySelector('[name=query]')?.value}))return;return refresh({quiet:true});};
 root.pageRefresh=async()=>{
  if(priceForm())throw new Error(t('Alış fiyatını kaydedin veya Vazgeç seçeneğini kullanın.'));
  if(model.busy)throw new Error(t('Devam eden stok işleminin tamamlanmasını bekleyin.'));
  const loaded=await refresh();
  if(model.error)throw new Error(model.error);
  return loaded;
 };
 let timer;
 const schedule=()=>{timer=setTimeout(async()=>{if(!current())return;await root.inventoryRefresh();schedule();},inventoryRefreshDelay(model.data));};
 schedule();root.inventoryDispose=()=>{clearTimeout(timer);reads.cancel();search.dispose();importDashboard?.dispose();importHeading.dispose();};
 paint();void refresh();
}
