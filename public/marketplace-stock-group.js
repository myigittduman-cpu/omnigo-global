import {api} from './api.js';
import {openDialog,esc} from './ui.js';

export async function openStockGroup(item,{company,current,refresh}){
 const dialog=openDialog('Bağlı pazaryerlerinde stoğu güncelle',
  '<form class="form-grid"><strong>'+esc(item.title||item.sku)+'</strong><p role="status" aria-live="polite">Aktif ilanlar kontrol ediliyor…</p><label>Yeni stok adedi<input name="quantity" type="number" min="0" max="100000000" step="1" required></label><button type="submit" class="btn primary" disabled>Listelenen ilanlara gönder</button></form>',
  {className:'inventory-dialog inventory-marketplace-dialog'});
 const form=dialog.querySelector('form'),status=form.querySelector('[role=status]'),button=form.querySelector('button');
 const alive=()=>current()&&dialog.open&&dialog.isConnected;
 const suffix='?company='+encodeURIComponent(company);
 let targets;
 try{
  const result=await api('/inventory/active-marketplace-listings'+suffix+'&productKey='+encodeURIComponent(item.writeTarget.productKey));
  if(!alive())return;
  targets=result.targets.map(target=>({...target,id:crypto.randomUUID()}));
  status.textContent=targets.map(t=>t.provider+' · '+t.sku+' · '+t.quantity+' adet').join('\n')||'Gönderime uygun aktif ilan yok.';
  button.disabled=!targets.length;
 }catch(error){if(alive())status.textContent=error.message;return;}
 form.onsubmit=async event=>{
  event.preventDefault();
  if(!alive()||button.disabled||!form.reportValidity())return;
  const quantity=form.elements.quantity.valueAsNumber;
  if(!Number.isSafeInteger(quantity)||quantity<0||quantity>100000000)return;
  button.disabled=true;form.elements.quantity.disabled=true;
  const changed=targets.filter(t=>!t.requestId&&t.quantity!==quantity);
  try{
   const reply=changed.length?await api('/inventory/active-marketplace-stock'+suffix,'POST',{
    action:'group',confirmed:true,productKey:item.writeTarget.productKey,quantity,
    targets:changed.map(t=>({requestId:t.id,provider:t.provider,listingKey:t.key,sourceQuantity:t.quantity,identityToken:t.identityToken}))
   },{confirmed:true}):{results:[]};
   if(alive())status.textContent=targets.map(t=>{
    const result=reply.results[changed.indexOf(t)];
    const message=t.requestId?'Önceki işlem sürüyor':t.quantity===quantity?'Stok zaten aynı':result?.error||'Kuyruğa alındı';
    return t.provider+' · '+t.sku+' — '+message;
   }).join('\n');
  }catch(error){if(alive()){status.textContent=error.message;button.disabled=false;}}
  if(current())void refresh();
 };
}
