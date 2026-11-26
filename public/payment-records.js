import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,money,date} from './ui.js';
import {paymentState} from './payment-approval.js';

const statusLabel={
 received:'Ödendi',confirmed:'Panel / ekstre ile teyitli',estimated:'Tahmini / teyitsiz',
};
export const paymentLabel=payment=>{
 if(payment.integration)return i18nT('Eski otomatik kayıt');
 const state=paymentState(payment);
 return i18nT(state==='sent'?(payment.status==='paid'?'Ödeme onayı bekliyor':'Banka teyidi bekleniyor'):statusLabel[state]);
};
export function paymentStatusMarkup(payment){
 const state=paymentState(payment);
 const check=state==='received'&&!payment.integration?i18nHtml`<span class="payment-approved-check" aria-hidden="true">✓</span>`:'';
 return i18nHtml`<span class="payment-state ${state}">${check}${esc(paymentLabel(payment))}</span>`;
}
export const paymentNoticeButton=()=>i18nHtml`<button class="btn" type="button" data-payment-notice>Ödendi Bildirimi</button>`;
export const paymentDate=p=>p.expectedDate?date(p.expectedDate):i18nT('Tarih belirlenmedi');
export function paymentCoverage(){return '';}
export function paymentRecords(rows,admin=false){
 if(!rows.length)return i18nHtml('<p class="report-empty">Bu tarih için yönetici tarafından eklenmiş hakediş bulunmuyor.</p>');
 return i18nHtml('<div class="report-table-scroll"><table><thead><tr><th>Pazaryeri / tarih</th><th>Net tutar</th><th>Durum / kaynak</th><th>Detay / işlem</th></tr></thead><tbody>')+rows.map(p=>i18nHtml('<tr><td><strong>')+esc(p.channel)+i18nHtml('</strong><small>Planlanan: ')+paymentDate(p)+(p.status==='paid'?i18nHtml('<br>Hesaba geçiş: ')+date(p.paidDate):'')+i18nHtml('</small></td><td>')+money(p.status==='paid'?p.actualCents:p.expectedCents)+(p.status==='paid'?i18nHtml('<small>Beklenen: ')+money(p.expectedCents)+i18nHtml('</small>'):'')+i18nHtml('</td><td>')+paymentStatusMarkup(p)+i18nHtml('<small>')+esc(p.status==='paid'?p.confirmation:p.verificationSource||p.source)+(p.excludedFromTotals?i18nHtml('<br>Arşiv kaydı; güncel toplam ve takvim dışında.'):'')+i18nHtml('</small></td><td><button class="text-btn" data-payment-detail="')+p.id+i18nHtml('">İncele</button>')+(admin&&!p.integration&&!p.paymentApproval?.at?' <button class="text-btn" data-calendar-edit="'+p.id+i18nHtml('">Düzelt</button>')+' <button class="text-btn" data-calendar-receipt="'+p.id+'">'+i18nT(p.status==='paid'?'Ödeme bilgisini düzenle':'Ödeme bilgisi gir')+'</button>':'')+i18nHtml('</td></tr>')).join('')+i18nHtml('</tbody></table></div>');
}
