import {api} from './api.js';
import {confirmMutation} from './action-confirmation.js';
import {html,t,text} from './i18n.js';
import {esc,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

export const editablePayment=payment=>Number.isSafeInteger(payment?.id)&&payment.id>0&&
 !payment.integration&&!payment.archivedAt;
const selectablePayment=payment=>editablePayment(payment)&&Number.isSafeInteger(payment.version)&&payment.version>0;

export function paymentSelectionToolbar(rows,selection,{showCount=true}={}){
 if(!selection)return '';
 const count=rows.filter(selectablePayment).length;
 if(!selection.mode)return count?html`<div class="payment-selection"><button class="btn" type="button" data-payment-select="start">Kayıt seç</button></div>`:'';
 const selected=selection.items.size,disabled=selection.busy?'disabled':'';
 const all=count>0&&selected===count;
 return html`<div class="payment-selection">
  ${showCount?html`<span role="status">${text`${selected} / ${count} kayıt seçildi`}</span>`:''}
  <button class="btn" type="button" data-payment-select="${all?'clear':'all'}" ${disabled} ${count?'':'disabled'}>${all?t('Seçimi temizle'):t('Tümünü seç')}</button>
  <button class="btn" type="button" data-payment-select="delete" ${disabled} ${selected?'':'disabled'}>Seçilenleri sil</button>
  <button class="btn" type="button" data-payment-select="cancel" ${disabled}>Seçimi kapat</button>
 </div>`;
}

export function paymentSelectionInput(payment,selection){
 if(!selection?.mode||!selectablePayment(payment))return '';
 return html`<input class="payment-select-input" type="checkbox" data-payment-select-id="${payment.id}" aria-label="${esc(text`${payment.channel} hakedişini seç`)}" ${selection.items.has(payment.id)?'checked':''} ${selection.busy?'disabled':''}>`;
}

export function createPaymentSelection({state,rows,render,onDeleted,current}){
 const token=workspaceToken(state),items=new Map();
 const selection={items,mode:false,busy:false};
 selection.reset=()=>{selection.mode=false;items.clear();};
 selection.sync=()=>{
  const available=new Set(rows().filter(selectablePayment).map(payment=>payment.id));
  for(const id of items.keys())if(!available.has(id))items.delete(id);
 };
 const valid=()=>current()&&sameWorkspace(state,token)&&state.user.role==='admin';
 const select=payment=>items.set(payment.id,payment.version);

 selection.bind=root=>{
  root.addEventListener('change',event=>{
   const input=event.target;
   if(!input.matches('[data-payment-select-id]')||!valid()||selection.busy||!selection.mode)return;
   const id=Number(input.dataset.paymentSelectId),payment=rows().find(row=>row.id===id&&selectablePayment(row));
   if(!payment)return;
   if(input.checked)select(payment);else items.delete(id);
   render();root.querySelector('[data-payment-select-id="'+id+'"]')?.focus({preventScroll:true});
  });
  root.addEventListener('click',async event=>{
   const button=event.target.closest('[data-payment-select]');
   if(!button||!valid()||selection.busy)return;
   const action=button.dataset.paymentSelect;
   if(action==='start')selection.mode=true;
   else if(action==='cancel')selection.reset();
   else if(action==='all')rows().filter(selectablePayment).forEach(select);
   else if(action==='clear')items.clear();
   else if(action==='delete'){await remove();return;}
   render();
   const next={start:'all',all:'clear',clear:'all',cancel:'start'}[action];
   if(next)root.querySelector('[data-payment-select="'+next+'"]')?.focus({preventScroll:true});
  });
 };

 async function remove(){
  selection.sync();
  if(!items.size){render();return;}
  const endpoint='/records/payments/selection/delete?company='+token.company;
  const payments=Array.from(items,([id,version])=>({id,version}));
  selection.busy=true;render();
  try{
   await confirmMutation(endpoint,'POST',{payments});
   if(!valid())return;
   const result=await api(endpoint,'POST',{payments},{confirmed:true});
   if(!valid())return;
   selection.reset();
   try{await onDeleted(payments.map(payment=>payment.id));toast(text`${result.deleted} hakediş silindi.`);}
   catch{toast(t('Hakedişler silindi. Güncel liste için ekranı yenileyin.'));}
  }catch(error){if(!error.cancelled&&valid())toast(error.message);}
  finally{selection.busy=false;if(valid())render();}
 }
 return selection;
}
