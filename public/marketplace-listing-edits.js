import {acceptDialogFieldDefaults} from './dialog-drafts.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {copyTableText} from './table-copy-button.js';
import {displayMoneyAmount,parseMoneyAmount} from './money-value.js';
import {openInventoryPurchasePrice} from './inventory-purchase-price.js';
import {listingTransferLines,listingTransferStatus} from './marketplace-listing-editor-view.js';

const quantityLimit=100000000;
const pause=(delay,signal)=>new Promise(resolve=>{
 if(signal.aborted){resolve();return;}
 const done=()=>{clearTimeout(timer);signal.removeEventListener('abort',done);resolve();};
 const timer=setTimeout(done,delay);
 signal.addEventListener('abort',done,{once:true});
});

export function changedListingValues(rows){
 const changes=[];
 for(const row of rows){
  const values={};
  for(const field of row.querySelectorAll('[data-listing-field]')){
   const raw=field.value.trim();
   if(!raw||raw===field.defaultValue.trim())continue;
   const name=field.dataset.listingField;
   const value=name==='quantity'&&/^\d+$/.test(raw)?Number(raw):name==='priceCents'?parseMoneyAmount(raw):null;
   if(!Number.isSafeInteger(value)||(name==='quantity'?value<0||value>quantityLimit:value<1)){
    throw new Error(row.dataset.channel+' için stok adedini veya satış fiyatını kontrol edin.');
   }
   const initial=field.defaultValue.trim();
   if(initial&&(name==='quantity'?Number(initial):parseMoneyAmount(initial))===value)continue;
   values[name]=value;
  }
  if(Object.keys(values).length)changes.push({provider:row.dataset.channel,...values});
 }
 return changes;
}

function openListingEditor(item,{path,current,company,onClose}){
 const dialog=openDialog('Pazaryeri stok ve fiyat düzenle',`
  <p class="listing-transfer-title"><strong>${esc(item.title||item.sku)}</strong> · ${esc(item.sku)}</p>
  <div class="listing-cost-controls"><button type="button" class="btn" data-listing-purchase disabled>Ortak alış fiyatını düzenle</button></div>
  <form class="form-grid listing-editor-form">
   <div class="listing-editor-table" tabindex="0" aria-label="Pazaryeri fiyat ve stokları"><table class="corporate-table">
    <thead><tr><th scope="col">Pazaryeri</th><th scope="col">Alış fiyatı (₺)</th><th scope="col">Pazaryeri satış fiyatı (₺)</th><th scope="col">Stok</th><th scope="col">Durum</th></tr></thead>
    <tbody>${listingTransferLines()}</tbody>
   </table></div>
   <p class="listing-transfer-message" role="status" aria-live="polite">Pazaryeri bilgileri okunuyor…</p>
   <p class="form-error" role="alert"></p>
   <button type="submit" class="btn primary" disabled>Pazaryerlerinde Güncelle</button>
  </form>`,{className:'listing-transfer-dialog'});
 const form=dialog.querySelector('.listing-editor-form'),body=form.querySelector('tbody');
 const status=form.querySelector('[role=status]'),error=form.querySelector('[role=alert]');
 const submit=form.querySelector('[type=submit]'),purchase=dialog.querySelector('[data-listing-purchase]');
 const costControls=purchase.parentElement,controller=new AbortController();
 const {signal}=controller,requestId=crypto.randomUUID();
 const card=item.cardKey??item.key;
 const alive=()=>current()&&dialog.open&&dialog.contains(form)&&!signal.aborted;
 let sending=false,polling=false,ambiguous=null,refreshNeeded=false,product=null;
 const fields=()=>[...form.querySelectorAll('[data-listing-field]')];
 const lock=flag=>fields().forEach(input=>{input.disabled=flag;});
 const setStatus=value=>{if(alive())status.textContent=value;};
 const setError=value=>{if(alive())error.textContent=value;};
 const report=()=>[...body.rows];
 const statusHref=id=>path+'&'+new URLSearchParams({transfer:card,...(id?{requestId:id}:{})});
 function enableSubmit(){
  submit.disabled=sending||polling||!product||!ambiguous&&!fields().some(input=>input.value.trim()&&input.value.trim()!==input.defaultValue.trim());
 }
 function paint(result){
  const byProvider=new Map(result.lines.map(line=>[line.provider,line]));
  for(const row of body.rows){
   const line=byProvider.get(row.dataset.channel);
   if(!line)continue;
   row.dataset.state=line.state;
   row.querySelector('[data-listing-state]').innerHTML=listingTransferStatus(line,{started:true});
  }
 }
 async function follow(result){
  polling=true;lock(true);enableSubmit();
  const href=statusHref(result.requestId);
  try{
   while(alive()){
    paint(result);
    if(result.done){
     refreshNeeded=true;
     const failed=result.lines.some(line=>!['succeeded','skipped'].includes(line.state));
     setStatus(failed?'Aktarım tamamlandı. Kırmızı işaretli pazaryerlerinin ayrıntılarını kontrol edin.':'Gönderilen değişiklikler pazaryerlerinde doğrulandı.');
     return;
    }
    setStatus('Aktarım sırada. Pazaryeri sonuçları doğrulandıkça burada görünecek.');
    await pause(2200,signal);
    if(!alive())return;
    try{result=await api(href,'GET',undefined,{signal});}
    catch(failure){
     if([401,403,404].includes(failure.status))throw failure;
     setStatus('Aktarım sunucuda devam ediyor. Durum yeniden okunacak.');
    }
   }
  }catch(failure){setError(failure.message);}
  finally{polling=false;}
 }
 function adopt(changes){
  for(const change of changes){
   const row=report().find(row=>row.dataset.channel===change.provider);
   for(const input of row?.querySelectorAll('[data-listing-field]')??[]){
    if(Object.hasOwn(change,input.dataset.listingField))input.defaultValue=input.value;
   }
  }
 }
 form.addEventListener('input',enableSubmit);
 form.onsubmit=async event=>{
  event.preventDefault();
  if(!alive()||sending||polling)return false;
  if(costControls.querySelector('[data-purchase-price-form]')){
   setError('Önce ortak alış fiyatı düzenlemesini bitirin.');return false;
  }
  let changes;
  try{changes=ambiguous??changedListingValues(report());}
  catch(failure){setError(failure.message);return false;}
  if(!changes.length){setError('Göndermek için stok veya satış fiyatını değiştirin.');return false;}
  sending=true;lock(true);purchase.disabled=true;enableSubmit();setError('');
  let result;
  try{
   result=await api(path,'POST',{action:'transfer',cardKey:card,confirmed:true,requestId,changes},{confirmed:true,signal});
  }catch(failure){
   if(!alive())return false;
   if(!failure.status||failure.status>=500){
    ambiguous=changes;
    setError('İstek sonucu alınamadı. Aynı gönderimi tekrar kontrol edebilirsiniz.');
    submit.textContent='Aynı gönderimi kontrol et';
   }else{ambiguous=null;lock(false);purchase.disabled=false;setError(failure.message);}
   return false;
  }finally{sending=false;if(alive())enableSubmit();}
  if(!alive())return false;
  ambiguous=null;adopt(changes);submit.disabled=true;
  void follow(result);
  return true;
 };
 purchase.onclick=()=>{
  if(!product||sending||polling||!alive())return;
  openInventoryPurchasePrice(product,{button:purchase,current:alive,endpoint:suffix=>'/inventory'+suffix+'?'+new URLSearchParams({company}),
   refresh:()=>{},onSaved:result=>{
    product={...product,...result};
    for(const cell of body.querySelectorAll('[data-listing-cost]'))cell.textContent=displayMoneyAmount(product.purchasePriceCents)||'—';
    refreshNeeded=true;
   }});
 };
 dialog.addEventListener('omnigo-dialog-dismiss',event=>{
  const costForm=costControls.querySelector('[data-purchase-price-form]');
  if(sending||costForm?.canLeaveInlineEdit?.()===false)event.preventDefault();
 });
 dialog.addEventListener('close',()=>{controller.abort();onClose(refreshNeeded);},{once:true});
 void (async()=>{
  try{
   const data=await api(path+'&'+new URLSearchParams({editor:card}),'GET',undefined,{signal});
   if(!alive())return;
   product={...data.product,title:data.product.title||item.title};
   body.innerHTML=listingTransferLines(data.lines,product.purchasePriceCents);
   acceptDialogFieldDefaults(form,fields().map(field=>field.name));
   if(data.latest?.requestId&&!data.latest.done){
    void follow(data.latest);
    return;
   }
   purchase.disabled=false;
   setStatus(fields().length?'Yalnız değiştirdiğiniz stok ve satış fiyatı gönderilir.': 'Güncellemeye uygun aktif ilan bulunamadı.');
  }catch(failure){setError(failure.message);setStatus('');}
 })();
 return dialog;
}

export function bindListingEdits(root,{company,current,itemFor,refresh}){
 const path='/inventory/active-marketplace-stock?'+new URLSearchParams({company});
 let editor=null;
 root.addEventListener('click',async event=>{
  const button=event.target.closest('[data-listing-edit],[data-listing-copy]');
  if(!button||!current())return;
  if(button.hasAttribute('data-listing-copy')){
   try{await copyTableText(button.dataset.listingCopy);if(current())toast('Kopyalandı.');}
   catch(error){if(current())toast(error.message);}
   return;
  }
  const item=itemFor(button.closest('tr').dataset.listing);
  if(!item||editor?.open)return;
  editor=openListingEditor(item,{path,current,company,onClose:needsRefresh=>{
   editor=null;if(current()&&needsRefresh)void refresh();
  }});
 });
 return {busy:()=>Boolean(editor?.open)};
}
