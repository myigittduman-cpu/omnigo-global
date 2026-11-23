import {html,t} from './i18n.js';
import {esc,money,now,openDialog,toast} from './ui.js';
import {api} from './api.js';
import {bindMoneyInputs} from './money-inputs.js';
import {bindDateInputs} from './date-inputs.js';
import {paymentReceiptPayload} from './payment-receipt.js';
export function paymentReceiptView(record,today=now()){
 return html`<form class="form-grid payment-receipt-form"><div class="payment-receipt-summary"><strong>${esc(record.channel)}</strong><span>Net hakediş: ${money(record.expectedCents)}</span></div>
 <label>Hesaba geçen tutar (₺)<input name="actualCents" type="text" inputmode="decimal" autocomplete="off" value="${esc((record.actualCents??record.expectedCents)/100)}" required></label>
 <label>Hesaba geçiş tarihi<input name="paidDate" type="date" value="${esc(record.paidDate||today)}" max="${today}" required></label>
 <p class="form-help">Kayıt, Ödendi Bildirimi ekranında ödeme onayı bekler. Onay verildiğinde Hesaba Geçen listesine ve toplamına eklenir.</p>
 <p class="form-error" role="alert"></p><div class="dialog-actions"><button class="btn" type="button" data-receipt-cancel>Vazgeç</button><button class="btn primary" type="submit">Kaydet</button></div></form>`;
}
export function paymentReceiptForm(record,state,onSave){
 if(state.user.role!=='admin'||record.integration||record.archivedAt||record.paymentApproval?.at)return;
 const company=state.company,dialog=openDialog(t(record.status==='paid'?'Ödeme bilgisini düzenle':'Ödeme bilgisi gir'),paymentReceiptView(record),{className:'payment-receipt-dialog'});
 const form=dialog.querySelector('form'),errorArea=form.querySelector('.form-error');let busy=false;
 bindMoneyInputs(form);bindDateInputs(form);
 form.querySelector('[data-receipt-cancel]').onclick=()=>{if(!busy)dialog.close();};
 form.onsubmit=async event=>{
  event.preventDefault();if(busy)return false;
  if(state.company!==company){errorArea.textContent=t('Firma değişti. Hakediş formunu yeniden açın.');return false;}
  const button=form.querySelector('[type=submit]');
  try{
   const payload=paymentReceiptPayload(record,Object.fromEntries(new FormData(form)),now());
   busy=true;button.disabled=true;button.textContent=t('Kaydediliyor…');errorArea.textContent='';
   await api(`/records/payments/${record.id}?company=${company}`,'PUT',payload);
   if(state.company!==company)return true;
   form.reset();dialog.close();
   try{await onSave();}catch{toast(t('Ödeme bilgisi kaydedildi. Liste yenilenemedi; tekrar kayıt eklemeyin, sayfayı yeniden açın.'));}
   return true;
  }catch(error){errorArea.textContent=error.message;return false;}
  finally{busy=false;button.disabled=false;button.textContent=t('Kaydet');}
 };
 return dialog;
}
