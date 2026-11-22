import {api} from './api.js';
import {esc,openDialog} from './ui.js';
import {marketplaceStockStates} from './inventory-marketplace-stock.js';

const quantityLimit=100000000;
const terminalStates=new Set(['succeeded','failed','uncertain']);

export function stockRecheckState(result){
 const uncertain=result.state==='uncertain';
 const pilot=typeof result.requestId==='string'&&result.requestId.startsWith('pilot:');
 return {available:uncertain&&pilot,note:uncertain&&!pilot?'Bu işlemin sonucunu Stok Yönetimi > Hareketler bölümünden takip edebilirsiniz.':''};
}

export function stockChangeBody(target,raw,requestId){
 if(!/^\d+$/.test(String(raw)))return null;
 const quantity=Number(raw);
 if(!Number.isSafeInteger(target?.quantity)||!Number.isSafeInteger(quantity)||quantity<0||quantity>quantityLimit||quantity===target.quantity)return null;
 return {requestId,productKey:target.productKey,listingKey:target.key,provider:target.provider,sourceQuantity:target.quantity,quantity,confirmed:true,
  ...(target.identityToken?{identityToken:target.identityToken}:{})};
}

export function openStockListingAction(item,{company,current,refresh}){
 const target=item.writeTarget;
 if(!target||!current())return;
 const existing=target.requestId;
 const identity=`<div class="marketplace-stock-identity"><strong>${esc(item.title||target.sku)}</strong><small>${esc(target.provider)} · ${esc(target.sku)}</small></div>`;
 const edit=`<label>Yeni stok adedi<input name="quantity" type="number" min="0" max="${quantityLimit}" step="1" inputmode="numeric" required autocomplete="off" value="${esc(target.quantity)}"></label>
  <label class="marketplace-stock-confirm"><input name="confirmed" type="checkbox" required><span data-stock-confirm-label></span></label>
  <button type="submit" class="btn primary" data-stock-submit>Onayla ve gönder</button>`;
 const dialog=openDialog(existing?'Stok gönderim durumu':'Pazaryeri stoğunu güncelle',`<form class="form-grid" data-pilot-stock-editor>${identity}
  <p>Son okunan kanal stoğu <strong>${esc(target.quantity)} adet</strong></p>
  ${existing?'':edit}<p role="status" aria-live="polite"></p><p class="form-error" role="alert"></p>
  <button type="button" class="btn secondary" data-stock-verify hidden>Pazaryerinden yeniden kontrol et</button></form>`,{className:'inventory-dialog inventory-marketplace-dialog'});
 const form=dialog.querySelector('form'),status=form.querySelector('[role=status]'),error=form.querySelector('[role=alert]');
 const submit=form.querySelector('[data-stock-submit]'),verify=form.querySelector('[data-stock-verify]');
 const createId=crypto.randomUUID(),path='/inventory/active-marketplace-stock?company='+encodeURIComponent(company);
 let requestId=existing,locked=false,timer,uncertainSubmit=false,latestState='';
 const alive=()=>current()&&dialog.open&&dialog.contains(form);
 function preview(){
  const raw=form.elements.quantity.value;
  const valid=stockChangeBody(target,raw,createId);
  form.querySelector('[data-stock-confirm-label]').textContent=valid?`${target.provider} mağazasında ${target.sku} stok adedinin ${target.quantity} yerine ${valid.quantity} olmasını onaylıyorum.`:'Yeni stok adedini girin.';
  submit.disabled=locked||!valid||!form.elements.confirmed.checked;
 }
 function readLater(){clearTimeout(timer);if(alive())timer=setTimeout(()=>void readStatus(),4000);}
 function show(result){
  if(!alive())return;
  requestId=result.requestId;latestState=result.state;
  const recheck=stockRecheckState(result);
  status.textContent=[marketplaceStockStates[result.state]||'Gönderim durumu kontrol ediliyor.',Number.isSafeInteger(result.quantity)?'İstenen stok: '+result.quantity+' adet.':'',recheck.note].filter(Boolean).join(' ');
  error.textContent=result.error||'';verify.hidden=!recheck.available;verify.disabled=false;
  if(terminalStates.has(result.state)){void refresh();return;}
  readLater();
 }
 async function readStatus(){
  if(!alive()||!requestId)return;
  try{show(await api(path+'&requestId='+encodeURIComponent(requestId)));}
  catch(failure){if(alive()){error.textContent=failure.message;if(![401,403,404].includes(failure.status))readLater();}}
 }
 if(existing){status.textContent='Gönderim durumu okunuyor…';void readStatus();}
 else{
  form.elements.quantity.oninput=()=>{form.elements.confirmed.checked=false;preview();};
  form.elements.confirmed.onchange=preview;preview();
 }
 form.onsubmit=async event=>{
  event.preventDefault();
  if(locked||requestId||!alive()||!form.elements.confirmed?.checked)return;
  const body=stockChangeBody(target,form.elements.quantity.value,createId);
  if(!body)return;
  locked=true;submit.disabled=true;form.elements.quantity.disabled=true;form.elements.confirmed.disabled=true;error.textContent='';
  try{
   const result=await api(path,'POST',body,{confirmed:true});
   if(!alive())return;
   form.elements.quantity.closest('label').hidden=true;form.elements.confirmed.closest('label').hidden=true;submit.hidden=true;
   locked=false;show(result);void refresh();
  }catch(failure){
   if(!alive())return;
   uncertainSubmit=uncertainSubmit||!failure.status||failure.status>=500;
   error.textContent=uncertainSubmit?'Gönderimin sonucu alınamadı. Aynı talebi yeniden kontrol edebilirsiniz.':failure.message;
   locked=false;form.elements.quantity.disabled=uncertainSubmit;form.elements.confirmed.disabled=uncertainSubmit;
   submit.textContent=uncertainSubmit?'Aynı talebi kontrol et':'Onayla ve gönder';preview();
   if(failure.status===409||failure.status===404)void refresh();
  }
 };
 verify.onclick=async()=>{
  if(!alive()||locked||!stockRecheckState({state:latestState,requestId}).available)return;
  locked=true;verify.disabled=true;
  try{show(await api(path,'POST',{action:'retry',requestId,confirmed:true},{confirmed:true}));}
  catch(failure){if(alive())error.textContent=failure.message;}
  finally{if(alive()){locked=false;verify.disabled=false;}}
 };
 dialog.addEventListener('close',()=>clearTimeout(timer),{once:true});
 return dialog;
}
