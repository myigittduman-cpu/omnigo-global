import {api} from './api.js';
import {confirmMutation} from './action-confirmation.js';
import {html,t} from './i18n.js';
import {bindMoneyInputs} from './money-inputs.js';
import {paymentAmount} from './payment-input.js';
import {esc,openDialog,toast} from './ui.js';
import {payoutSourceWarning} from './payout-source-warning.js';

export function openUpcomingPaymentEntry({company,channels,today,current,onSave,warnings}){
 if(!channels.length||!current())return;
 let busy=false;
 const dialog=openDialog(t('Manuel hakediş gir'),html`
  ${payoutSourceWarning(warnings)}
  <form class="form-grid">
   <label class="full-width">Pazaryeri
    <select name="channel" required>
     <option value="">Pazaryeri seçin</option>
     ${channels.map(channel=>html`<option value="${esc(channel)}">${esc(channel)}</option>`).join('')}
    </select>
   </label>
   <label>Ödeme tarihi <input name="expectedDate" type="date" min="${esc(today)}" required></label>
   <label>Tahmini net tutar (TL) <input name="expectedCents" inputmode="decimal" required></label>
   <p class="full-width">Aynı pazaryeri ve tarihte bekleyen bir tahmin varsa tutarı güncellenir.</p>
   <p class="form-error full-width" role="alert"></p>
   <div class="operation-form-actions full-width">
    <button type="button" class="btn" data-payment-entry-cancel>Vazgeç</button>
    <button type="submit" class="btn primary">Kaydet</button>
   </div>
  </form>`,{className:'upcoming-period-dialog'});
 const form=dialog.querySelector('form'),amount=form.elements.expectedCents;
 const errorArea=form.querySelector('.form-error'),submit=form.querySelector('[type=submit]');
 const alive=()=>current()&&dialog.open&&form.isConnected;
 bindMoneyInputs(form);
 if(channels.length===1)form.elements.channel.value=channels[0];
 form.querySelector('[data-payment-entry-cancel]').onclick=()=>{if(!busy)dialog.querySelector('.close').click();};
 const protectSave=event=>{if(busy&&form.isConnected)event.preventDefault();};
 dialog.addEventListener('omnigo-dialog-dismiss',protectSave);
 dialog.addEventListener('close',()=>dialog.removeEventListener('omnigo-dialog-dismiss',protectSave),{once:true});
 form.onsubmit=async event=>{
  event.preventDefault();
  if(busy||!alive())return;
  errorArea.textContent='';
  const data=Object.fromEntries(new FormData(form));
  try{data.expectedCents=paymentAmount(amount.value);}
  catch(error){errorArea.textContent=error.message;amount.focus();return;}
  if(data.expectedCents<=0){errorArea.textContent=t('Sıfırdan büyük bir net tutar girin.');amount.focus();return;}
  Object.assign(data,{status:'pending',verification:'estimated',source:'Manuel hakediş tahmini'});
  const endpoint='/records/payments?company='+company;
  busy=true;submit.disabled=true;
  try{
   await confirmMutation(endpoint,'POST',data);
   if(!alive())return;
   await api(endpoint,'POST',data,{confirmed:true});
   if(!alive())return;
   form.reset();dialog.close();
   try{await onSave();if(current())toast(t('Hakediş kaydedildi.'));}
   catch{if(current())toast(t('Hakediş kaydedildi. Listeyi görmek için sayfayı yenileyin.'));}
  }catch(error){if(!error.cancelled&&alive())errorArea.textContent=error.message;}
  finally{busy=false;if(alive())submit.disabled=false;}
 };
}
