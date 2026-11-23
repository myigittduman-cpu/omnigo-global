import {api} from './api.js';
import {confirmAction} from './action-confirmation.js';
import {html,t,text} from './i18n.js';
import {notificationFields} from './notification-detail-view.js';
import {upcomingPaymentDate} from './upcoming-payment-model.js';
import {esc,money,toast} from './ui.js';

export const isUpcomingPayoutNotice=notice=>notice.events?.includes('upcoming_payout_amount_changed')===true;

function reviewFields(change){
 return notificationFields([
  [t('Pazaryeri'),change.channel],
  [t('Ödeme tarihi'),upcomingPaymentDate(change.date)],
  [t('Son onaylanan tutar'),money(change.beforeCents)],
  [t('Pazaryerindeki yeni tutar'),money(change.proposedCents)],
 ]);
}

export function upcomingPayoutReview(changes=[]){
 if(!changes.length)return '';
 return html`<section class="upcoming-payments-review" aria-label="Hakediş tutarı değişiklikleri">
  <h3>Onay bekleyen tutar değişiklikleri</h3>
  ${changes.map(change=>html`<article class="health-card">
   ${reviewFields(change)}
   <div class="operation-form-actions"><button type="button" class="btn" data-upcoming-review="${esc(change.key)}">Tutarı incele</button></div>
  </article>`).join('')}
 </section>`;
}

export function upcomingPayoutNoticeDetail(notice){
 const change=notice.payoutChange;
 if(change)return upcomingPayoutReview([change]);
 return html`<section class="notification-detail-summary">
  ${notificationFields([[t('Pazaryeri'),notice.payoutChannel],[t('Ödeme tarihi'),upcomingPaymentDate(notice.payoutDate)]])}
  <p>Bu bildirim için bekleyen bir değişiklik kalmadı. Güncel durumu Hakedişlerim bölümünde görebilirsiniz.</p>
  <div class="operation-form-actions"><button type="button" class="btn" data-customer-order-notification-view="payments">Hakedişlerim sayfasını aç</button></div>
 </section>`;
}

export async function reviewUpcomingPayout(change,{company,current,onSave}){
 const companyId=Number(company?.id);
 if(!change||!Number.isSafeInteger(companyId)||companyId<1||!current())return false;
 const previous=money(change.beforeCents),proposed=money(change.proposedCents);
 const day=upcomingPaymentDate(change.date);
 const message=text`${change.channel} için ${day} tarihli hakediş ${previous} tutarından ${proposed} tutarına değişti. Tablodaki tutar güncellensin mi?`;
 const approved=await confirmAction(message,{
  heading:t('Hakediş tutarını onaylayın'),approveLabel:t('Evet, güncelle'),scope:company.name,
 });
 if(!approved||!current())return false;
 try{
  await api('/payments/upcoming-review','POST',{company:companyId,key:change.key,revision:change.revision},{confirmed:true});
 }catch(error){
  if(!current())return false;
  if(error.status===409){
   try{await onSave?.();}catch{ /* The next page refresh will load the latest proposal. */ }
  }
  if(current())toast(error.message);
  return false;
 }
 if(current()){
  try{await onSave?.();}catch{ /* The approval is committed. Live refresh will retry the view. */ }
  if(current())toast(t('Hakediş tutarı güncellendi.'));
 }
 return true;
}
