import {api} from './api.js';
import {esc} from './ui.js';
import {displayMoneyAmount,parseMoneyAmount} from './money-value.js';
import {enhanceMoneyInputs} from './money-inputs.js';
import {choosePurchasePriceScope} from './inventory-purchase-scope.js';
import {grossPurchasePrice} from './inventory-price-view.js';

function priceForm(item){
 const form=document.createElement('form');
 form.className='inventory-inline-price';
 form.dataset.purchasePriceForm='';
 form.dataset.inlineEdit='';
 form.noValidate=true;
 form.innerHTML=`<input class="btn secondary inventory-price-button" name="purchasePriceCents" type="text" data-try-prefix inputmode="decimal" enterkeyhint="done" autocomplete="off" aria-label="${esc(item.title)} KDV dahil alış fiyatı (₺)" placeholder="0,00" value="${displayMoneyAmount(grossPurchasePrice(item))}" required>
 <button class="inventory-price-hint" type="submit" aria-label="KDV dahil alış fiyatını kaydet" title="Alış fiyatını kaydet">KDV Dahil</button>`;
 return form;
}

function priceValues(form,item){
 const amountCents=parseMoneyAmount(form.elements.purchasePriceCents.value);
 if(Number.isInteger(item.purchaseVatRateBasisPoints)){
  return {amountCents,vatBasis:'included',vatRateBasisPoints:item.purchaseVatRateBasisPoints};
 }
 // The amount-only API stores gross cost. Do not invent a rate to derive a net cost.
 return {amountCents};
}

export function openInventoryPurchasePrice(item,{
 button=document.querySelector(`[data-purchase-price="${CSS.escape(item.key)}"]`),
 endpoint,current,refresh,canEdit=current,onSaved=()=>refresh(),onClose=()=>refresh(),
}){
 if(!button||item.removed||!canEdit())return;
 const form=priceForm(item),amount=form.elements.purchasePriceCents;
 const productKey=item.key,version=item.purchasePriceVersion||0;
 let choosing=false,saving=false,lastPayload,requestId;
 const editable=()=>form.isConnected&&current()&&canEdit();
 button.replaceWith(form);
 enhanceMoneyInputs(form);
 function close(focus){
  form.replaceWith(button);
  if(focus)button.focus({preventScroll:true});
 }
 function cancel(focus){
  if(saving||choosing)return;
  close(focus);
  onClose();
 }
 form.addEventListener('keydown',event=>{
  if(event.isComposing)return;
  if(event.key==='Escape'){event.preventDefault();event.stopPropagation();cancel(true);}
  if(event.key==='Enter'&&event.target.matches('input')){
   event.preventDefault();
   if(!event.repeat)form.requestSubmit();
  }
 });
 const original=amount.value;
 function report(message){
  amount.setCustomValidity(message);
  amount.reportValidity();
 }
 form.canLeaveInlineEdit=()=>{
  if(choosing)return false;
  if(!saving&&amount.value===original)return true;
  if(!saving){
   amount.focus({preventScroll:true});
   report('Kaydetmek için Enter’a veya KDV Dahil yazısına basın. Düzenlemeden çıkmak için Esc kullanın.');
  }
  return false;
 };
 form.closeInlineEdit=()=>close(false);
 form.addEventListener('focusout',event=>{
  if(!choosing&&!saving&&event.relatedTarget&&!form.contains(event.relatedTarget)&&amount.value===original)close(false);
 });
 form.onsubmit=async event=>{
  event.preventDefault();
  if(choosing||saving||!editable())return;
  amount.setCustomValidity('');
  try{
   const values=priceValues(form,item);
   const trigger=document.activeElement;
   choosing=true;
   const applyToPastOrders=await choosePurchasePriceScope(item,editable);
   if(!editable())return;
   if(applyToPastOrders===null){
    if(form.contains(trigger))trigger.focus({preventScroll:true});
    return;
   }
   const payload={...values,applyToPastOrders};
   const signature=JSON.stringify(payload);
   if(signature!==lastPayload){requestId=crypto.randomUUID();lastPayload=signature;}
   saving=true;
   for(const control of form.elements)control.disabled=true;
   const result=await api(endpoint('/purchase-price'),'POST',{productKey,version,requestId,...payload});
   if(current()&&form.isConnected){
    close(true);
    onSaved(result);
   }
  }catch(error){
   if(current()&&form.isConnected){amount.disabled=false;report(error.message);}
  }finally{
   choosing=false;
   saving=false;
   if(form.isConnected)for(const control of form.elements)control.disabled=false;
  }
 };
 amount.focus({preventScroll:true});
 amount.select();
}
