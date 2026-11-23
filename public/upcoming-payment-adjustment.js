import {api} from './api.js';
import {html,t} from './i18n.js';
import {bindMoneyInputs} from './money-inputs.js';
import {paymentAmount} from './payment-input.js';
import {esc,money,openDialog,toast} from './ui.js';
import {editablePayment} from './payment-selection.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';

export const canRemoveCalendarPayment=item=>item.forecastReadOnly?
 Boolean(item.adjustmentRevision):editablePayment(item)&&Number.isSafeInteger(item.version)&&item.version>0&&!item.paymentApproval?.at;

export function openCalendarPaymentRemoval(item,{state,current,onSave}){
 if(state.user.role!=='admin'||!canRemoveCalendarPayment(item))return;
 const token=workspaceToken(state);
 return openUpcomingPaymentAdjustment({
  id:item.id,channel:item.channel,date:item.expectedDate,amountCents:item.expectedCents,adjustmentRevision:item.adjustmentRevision,
 },{company:token.company,remove:true,current:()=>sameWorkspace(state,token)&&current(),
  onSave,record:item.forecastReadOnly?null:item});
}

export function openUpcomingPaymentAdjustment(item,{company,today,remove,current,onSave,record}){
 if(!current()||(!item.adjustmentRevision&&!(remove&&record)))return;
 const dialog=openDialog(remove?t('Hakediş tablodan kaldırılsın mı?'):t('Otomatik hakedişi düzenle'),html`
  <form class="form-grid">
   <p class="full-width"><strong>${esc(item.channel)}</strong> · ${esc(item.date)} · ${money(item.amountCents)}</p>
   ${remove?(record?'':html`<p class="full-width">Pazaryeri bu kaydı aktarmaya devam eder. Kayıt tablodan ve toplamdan kaldırılır.</p>`):html`
    <label>Ödeme tarihi <input name="date" type="date" value="${esc(item.date)}" min="${esc(today)}" required></label>
    <label>Net hakediş (TL) <input name="amount" inputmode="decimal" value="${item.amountCents/100}" required></label>`}
   <p class="form-error full-width" role="alert"></p>
   <div class="dialog-actions full-width">
    <button type="button" class="btn" data-adjustment-cancel>Vazgeç</button>
    <button type="submit" class="btn primary">${remove?t('Evet, kaldır'):t('Kaydet')}</button>
   </div>
  </form>`);
 const form=dialog.querySelector('form');
 const submit=form.querySelector('[type=submit]');
 const alive=()=>current()&&dialog.open&&form.isConnected;
 bindMoneyInputs(form);
 form.querySelector('[data-adjustment-cancel]').onclick=()=>{if(!submit.disabled)dialog.close();};
 const protect=event=>{if(submit.disabled)event.preventDefault();};
 dialog.addEventListener('omnigo-dialog-dismiss',protect);
 dialog.addEventListener('close',()=>dialog.removeEventListener('omnigo-dialog-dismiss',protect),{once:true});
 form.onsubmit=async event=>{
  event.preventDefault();
  if(!alive()||submit.disabled)return;
  submit.disabled=true;
  const errorArea=form.querySelector('[role=alert]');
  errorArea.textContent='';
  try{
   if(record)await api('/records/payments/'+record.id+'?company='+company,'DELETE',{version:record.version},{confirmed:true});
   else{
    const data={company,id:item.id,revision:item.adjustmentRevision,action:remove?'remove':'edit'};
    if(!remove)Object.assign(data,{date:form.elements.date.value,amountCents:paymentAmount(form.elements.amount.value)});
    await api('/payments/upcoming-adjustment','POST',data,{confirmed:true});
   }
   if(!alive())return;
   dialog.close();
   try{await onSave();if(current())toast(remove?t('Hakediş kaldırıldı.'):t('Hakediş güncellendi.'));}
   catch{if(current())toast(t('İşlem kaydedildi. Listeyi görmek için sayfayı yenileyin.'));}
  }catch(error){if(alive())errorArea.textContent=error.message;}
  finally{submit.disabled=false;}
 };
 return dialog;
}
