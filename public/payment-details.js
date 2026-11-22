import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,money,date,openDialog} from './ui.js';
import {paymentSections} from './payment-templates.js';
import {paymentDifferenceNote} from './payment-difference-note.js';
import {bindPaymentDelete} from './payment-delete.js';
import {paymentLabel} from './payment-records.js';
export function paymentDetailSections(payment){
 const tax={included:'KDV dahil',excluded:'KDV hariç',mixed:'Kalemlere göre değişiyor'}[payment.taxBasis];
 const metadata=[
  payment.periodStart&&payment.periodEnd?i18nHtml`<p>Dönem: ${date(payment.periodStart)} – ${date(payment.periodEnd)}</p>`:'',
  payment.reference?.trim()?i18nHtml`<p>Ekstre / ödeme referansı: ${esc(payment.reference)}</p>`:'',
  tax?i18nHtml`<p>KDV durumu: ${i18nT(tax)}</p>`:'',
  payment.notes?.trim()?i18nHtml`<p class="payment-notes">${esc(payment.notes)}</p>`:'',
 ].join('');
 const rows=(payment.details||[]).filter(row=>row.amountCents!=null||row.rateBasisPoints!=null);
 const sections=Object.entries(paymentSections).map(([section,title])=>{
  const entries=rows.filter(row=>row.section===section);
  return entries.length?i18nHtml`<section><h4>${title}</h4><dl>${entries.map(row=>i18nHtml`<div><dt>${esc(row.label)}${row.rateBasisPoints==null?'':i18nHtml`<small>Oran: %${(row.rateBasisPoints/100).toLocaleString(currentLocale(),{maximumFractionDigits:2})}</small>`}</dt>${row.amountCents==null?'':i18nHtml`<dd>${money(row.amountCents)}</dd>`}</div>`).join('')}</dl></section>`:'';
 }).join('');
 if(!metadata&&!sections)return '';
 return i18nHtml`<div class="payment-detail-sections">${metadata?i18nHtml`<section><h3>Dönem, ekstre ve açıklama</h3>${metadata}</section>`:''}${sections?i18nHtml`<div class="payment-detail-lines"><h3>Kalem detayları</h3>${sections}</div>`:''}</div>`;
}
export function openPaymentDetail(p,{state,onSave,current}={}){
 const dialog=openDialog(i18nT('Hakediş detayı'),i18nHtml`<div class="detail-line"><span>Pazaryeri</span><strong>${esc(p.channel)}</strong></div><div class="detail-line"><span>Net hakediş</span><strong>${money(p.expectedCents)}</strong></div><div class="detail-line"><span>Planlanan ödeme</span><strong>${date(p.expectedDate)}</strong></div><div class="detail-line"><span>Durum</span><strong>${esc(paymentLabel(p))}</strong></div>${p.status==='paid'?i18nHtml`<div class="detail-line"><span>${p.paymentApproval?.at?i18nT('Hesaba geçen'):i18nT('Bildirilen tutar')}</span><strong>${money(p.actualCents)} · ${date(p.paidDate)}</strong></div>${paymentDifferenceNote(p)}${p.confirmation?i18nHtml`<p>Teyit: ${esc(p.confirmation)}</p>`:''}`:''}${paymentDetailSections(p)}${p.updated?i18nHtml`<p class="detail-note">Son güncelleme: ${esc(new Date(p.updated).toLocaleString(currentLocale()))}</p>`:''}`);
 bindPaymentDelete(dialog.querySelector('.dialog-body'),p,state,onSave,current);return dialog;
}
