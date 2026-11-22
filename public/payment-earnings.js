import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {money} from './ui.js';
import {paymentRecords} from './payment-records.js';
export function paymentEarnings(items=[],admin=false){
 if(!items.length)return '';
 return i18nHtml`<section class="report-panel"><div class="report-section-title"><h2>Siparişlerden bildirilen hakediş</h2><strong>${money(items.reduce((sum,item)=>sum+item.expectedCents,0))}</strong></div><p class="report-note">İdefix’in aktarılan sipariş satırlarında bildirdiği hakediş tutarıdır; iptal ve onaylı iadeler çıkarılır. Kaynak ödeme tarihi ve ödeme durumu bildirmediği için bekleyen bakiye, aylık ödeme toplamı veya hesaba geçen tutar olarak sayılmaz. Tutar, yalnız seçili ayı değil aktarılan sipariş dönemini kapsar.</p>${paymentRecords(items,admin)}</section>`;
}
