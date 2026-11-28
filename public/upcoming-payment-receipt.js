import {api} from './api.js';
import {confirmAction} from './action-confirmation.js';
import {html,t,text} from './i18n.js';
import {bindMoneyInputs} from './money-inputs.js';
import {bindDateInputs} from './date-inputs.js';
import {paymentAmount} from './payment-input.js';
import {validCalendarDate} from './calendar-date.js';
import {esc,money,now,openDialog,toast} from './ui.js';

export function openUpcomingPaymentReceipt(item,{state,record,current,onSave}){
 if(state.user?.role!=='admin'||!current()||item.paymentApproval?.at)return;
 const company=state.company;
 const dialog=openDialog(t('Hakedişin hesaba geçtiğini onayla'),html`<form class="form-grid">
  <p class="full-width"><strong>${esc(item.channel)}</strong> · ${esc(item.date.split('-').reverse().join('.'))} · ${money(item.amountCents)}</p>
  <label>Hesaba geçen tutar (₺)<input name="actualCents" inputmode="decimal" value="${item.amountCents/100}" required></label>
  <label>Hesaba geçiş tarihi<input name="paidDate" type="date" value="${now()}" required></label>
  <p class="form-help full-width">Onaylandığında yeşil tik gösterilir ve ödeme, hesaba geçiş tarihinin ait olduğu dönemin toplamına eklenir.</p>
  <p class="form-error full-width" role="alert"></p>
  <div class="dialog-actions full-width"><button type="button" class="btn" data-receipt-cancel>Vazgeç</button><button type="submit" class="btn primary">Hesaba geçtiğini onayla</button></div>
 </form>`);
 const form=dialog.querySelector('form'),button=form.querySelector('[type=submit]'),errorArea=form.querySelector('[role=alert]');let busy=false;
 const alive=()=>current()&&state.company===company&&dialog.open&&form.isConnected;
 bindMoneyInputs(form);bindDateInputs(form);
 form.querySelector('[data-receipt-cancel]').onclick=()=>{if(!busy)dialog.close();};
 const protect=event=>{if(busy)event.preventDefault();};dialog.addEventListener('omnigo-dialog-dismiss',protect);
 dialog.addEventListener('close',()=>dialog.removeEventListener('omnigo-dialog-dismiss',protect),{once:true});
 form.onsubmit=async event=>{
  event.preventDefault();if(busy||!alive())return;
  busy=true;button.disabled=true;errorArea.textContent='';
  try{
   const actualCents=paymentAmount(form.elements.actualCents.value),paidDate=form.elements.paidDate.value;
   if(!validCalendarDate(paidDate))throw new Error(t('Geçerli bir tarih girin.'));
   const message=text`${item.channel} hakedişinin ${money(actualCents)} tutarında, ${paidDate.split('-').reverse().join('.')} tarihinde hesaba geçtiğini onaylıyor musunuz?`;
   if(!await confirmAction(message)||!alive())return;
   await api('/payments/upcoming-receipt','POST',{company,id:item.id,revision:item.receiptRevision,version:record?.version,actualCents,paidDate},{confirmed:true});
   if(!alive())return;dialog.close();
   try{await onSave();if(current())toast(t('Hakedişin hesaba geçtiği onaylandı.'));}
   catch{if(current())toast(t('Onay kaydedildi. Listeyi görmek için sayfayı yenileyin.'));}
  }catch(error){if(alive())errorArea.textContent=error.message;}
  finally{busy=false;button.disabled=false;}
 };
 return dialog;
}
