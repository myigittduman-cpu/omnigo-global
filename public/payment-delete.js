import {html,t} from './i18n.js';
import {api} from './api.js';
import {esc,money,date,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

export const canDeletePayment=(payment,state)=>state?.user?.role==='admin'&&Number.isInteger(payment?.id)&&!payment.integration&&!payment.archivedAt&&!payment.paymentApproval?.at;
export function bindPaymentDelete(root,payment,state,onSave=async()=>{},current=()=>true){
 if(!root||!canDeletePayment(payment,state))return;
 const token=workspaceToken(state),area=document.createElement('div');area.className='payment-delete-area';
 area.innerHTML=html`<button type="button" class="btn" data-payment-delete>Hakedişi sil</button><div class="payment-delete-confirmation" data-payment-delete-prompt hidden><p><strong>${esc(payment.channel)}</strong> · ${date(payment.expectedDate)} · ${money(payment.expectedCents)}</p><p>Bu hakediş ve varsa ödeme bilgisi tablodan ve toplamlardan kaldırılacak. İşlem kaydı korunacak.</p><div class="payment-record-actions"><button type="button" class="btn" data-payment-delete-confirm>Evet, hakedişi sil</button><button type="button" class="btn" data-payment-delete-cancel>Vazgeç</button></div><p role="alert" class="form-error"></p></div>`;
 root.append(area);const prompt=area.querySelector('[data-payment-delete-prompt]'),button=area.querySelector('[data-payment-delete]');let busy=false;
 const valid=()=>root.isConnected&&sameWorkspace(state,token)&&current();
 button.onclick=()=>{if(valid()){prompt.hidden=false;button.hidden=true;}};
 area.querySelector('[data-payment-delete-cancel]').onclick=()=>{if(!busy){prompt.hidden=true;button.hidden=false;button.focus({preventScroll:true});}};
 area.querySelector('[data-payment-delete-confirm]').onclick=async()=>{
  if(busy||!valid())return;busy=true;prompt.querySelectorAll('button').forEach(b=>b.disabled=true);
  try{await api('/records/payments/'+payment.id+'?company='+token.company,'DELETE',{version:payment.version},{confirmed:true});if(!valid())return;root.closest('dialog')?.close();try{await onSave();toast(t('Hakediş silindi.'));}catch{toast(t('Hakediş silindi. Listeyi yeniden açın; silme işlemini tekrar etmeyin.'));}}
  catch(error){if(valid())prompt.querySelector('[role=alert]').textContent=error.message;}
  finally{busy=false;prompt.querySelectorAll('button').forEach(b=>b.disabled=false);}
 };
}
