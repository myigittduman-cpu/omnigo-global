import {paymentAmount,validatePaymentForm,revealSavedPayment,revealPaymentField} from './payment-input.js';
import {bindMoneyInputs} from './money-inputs.js';
import {t as i18nT,text as i18nText} from './i18n.js';
import {api} from './api.js';
import {confirmMutation} from './action-confirmation.js';
import {openDialog,toast} from './ui.js';
import {paymentTemplate,paymentTemplateNote} from './payment-templates.js';
import {paymentFormHtml,paymentRowsHtml,paymentLineHtml} from './payment-form-view.js';
import {paymentIsApproved} from './payment-approval.js';
import {payoutSourceWarning} from './payout-source-warning.js';
export {paymentDueDefault,paymentRowsHtml} from './payment-form-view.js';
export function collectPaymentLines(form,{includeEmpty=false}={}){return [...form.querySelectorAll('[data-payment-line]')].map(line=>({section:line.dataset.paymentLine,label:line.querySelector('[data-line-label]').value.trim(),amountCents:paymentAmount(line.querySelector('[data-line-amount]').value,{optional:true,negative:true}),rateBasisPoints:paymentAmount(line.querySelector('[data-line-rate]').value,{optional:true,rate:true})})).filter(row=>includeEmpty||row.amountCents!==null||row.rateBasisPoints!==null);}
export function manualPaymentForm(record,state,onSave,{expectedDate,preferredChannel,warnings}={}){
 if(state.user.role!=='admin'){toast(i18nT('Hakedişleri yalnız yönetici düzenleyebilir.'));return;}
 if(record?.integration||record?.archivedAt)return;
 const company=state.company;
 const r=record||{expectedDate},channels=state.data.channels.map(c=>[c.name,c.name]);
 const channel=r.channel||(channels.some(([name])=>name===preferredChannel)?preferredChannel:channels[0]?.[0])||'';
 const dialog=openDialog(record?i18nT('Hakedişi düzelt'):i18nT('Hakediş ekle'),payoutSourceWarning(warnings)+paymentFormHtml(r,channels,channel),{className:'payment-form-dialog'});
 const form=dialog.querySelector('form'),drafts=new Map();let selected=channel,busy=false;form.noValidate=true;
 bindMoneyInputs(form);
 function updateCounts(){for(const group of form.querySelectorAll('[data-payment-group]')){const count=[...group.querySelectorAll('[data-payment-line]')].filter(line=>line.querySelector('[data-line-amount]').value.trim()||line.querySelector('[data-line-rate]').value.trim()).length;group.querySelector('[data-payment-count]').textContent=count?i18nText`${count} kalem girildi`:i18nT('Kalem girilmedi');}}
 form.addEventListener('input',updateCounts);
 form.addEventListener('invalid',event=>revealPaymentField(event.target),true);
 function showLines(rows){form.querySelector('[data-payment-lines]').innerHTML=paymentRowsHtml(rows);form.querySelector('[data-template-note]').textContent=paymentTemplateNote(selected);updateCounts();}
 showLines(r.details?.length?r.details:paymentTemplate(channel));
 form.elements.channel.onchange=()=>{
  try{drafts.set(selected,collectPaymentLines(form,{includeEmpty:true}));selected=form.elements.channel.value;showLines(drafts.get(selected)||paymentTemplate(selected));form.querySelector('.form-error').textContent='';}
  catch(error){form.elements.channel.value=selected;form.querySelector('.form-error').textContent=error.message;toast(error.message);}
 };
 const toggle=()=>{const paid=form.elements.status.value==='paid',verified=form.elements.verification.value==='confirmed';form.querySelector('[data-paid-fields]').hidden=!paid;for(const key of ['actualCents','paidDate']){form.elements[key].disabled=!paid;form.elements[key].required=paid;}form.elements.verificationSource.closest('label').hidden=!verified;form.elements.verificationSource.disabled=!verified;form.elements.verificationSource.required=verified;};toggle();form.elements.status.onchange=toggle;form.elements.verification.onchange=toggle;
 form.querySelector('[data-cancel]').onclick=()=>{if(!busy)dialog.close();};
 form.addEventListener('click',event=>{const add=event.target.closest('[data-payment-line-add]'),remove=event.target.closest('[data-payment-line-remove]');if(remove){const group=remove.closest('[data-payment-group]');remove.closest('[data-payment-line]').remove();group.querySelector('[data-payment-line-add]').focus();updateCounts();}if(add){if(form.querySelectorAll('[data-payment-line]').length>=40){toast(i18nT('En fazla 40 kalem eklenebilir.'));return;}form.querySelector(`[data-payment-section="${add.dataset.paymentLineAdd}"]`).insertAdjacentHTML('beforeend',paymentLineHtml({section:add.dataset.paymentLineAdd,label:''}));const group=add.closest('[data-payment-group]');const lines=group.querySelectorAll('[data-line-label]');lines[lines.length-1].focus();updateCounts();}});
 form.onsubmit=async event=>{
  event.preventDefault();if(busy)return false;
  const errorArea=form.querySelector('.form-error'),validation=validatePaymentForm(form);
  errorArea.textContent=validation;if(validation){toast(validation);return false;}
  if(state.company!==company){errorArea.textContent=i18nT('Firma değişti. Hakediş formunu yeniden açın.');return false;}
  const button=form.querySelector('[type=submit]');busy=true;button.disabled=true;button.textContent=i18nT('Kaydediliyor…');
  try{
   const data=Object.fromEntries(new FormData(form));delete data[''];data.details=collectPaymentLines(form);
   data.source=r.source||'Omnigo girişi';
   for(const key of ['expectedCents','actualCents'])if(data[key]!==undefined)data[key]=paymentAmount(data[key]);
   if(data.status==='paid'&&data.actualCents===r.actualCents&&data.paidDate===r.paidDate&&r.confirmation)data.confirmation=r.confirmation;
   if(r.id)data.version=r.version;
   if(paymentIsApproved(r))data.correctApproved=true;
   const endpoint=`/records/payments${r.id?'/'+r.id:''}?company=${company}`,method=r.id?'PUT':'POST';
   await confirmMutation(endpoint,method,data);
   if(!dialog.open||state.company!==company)return false;
   await api(endpoint,method,data,{confirmed:true});
   if(state.company!==company)return true;
   if(!r.id)revealSavedPayment(state,data);
   form.reset();dialog.close();
   try{await onSave();toast(i18nT('Hakediş kaydedildi.'));}
   catch{toast(i18nT('Hakediş kaydedildi. Liste yenilenemedi; tekrar kayıt eklemeyin, sayfayı yeniden açın.'));}
   return true;
  }catch(error){if(!error.cancelled){errorArea.textContent=error.message;errorArea.scrollIntoView({block:'nearest'});}return false;}
  finally{busy=false;button.disabled=false;button.textContent=i18nT('Kaydet');}
 };
 return dialog;
}
