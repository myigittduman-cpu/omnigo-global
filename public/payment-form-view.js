import {t as i18nT,html as i18nHtml} from './i18n.js';
import {esc,now} from './ui.js';
import {paymentSections} from './payment-templates.js';

const input=(label,name,value='',type='text',attrs='')=>i18nHtml`<label>${i18nT(label)}<input name="${name}" value="${esc(value)}" type="${type}" ${attrs}></label>`;
const options=(items,value)=>items.map(([key,label])=>i18nHtml`<option value="${esc(key)}" ${key===value?'selected':''}>${esc(i18nT(label))}</option>`).join('');

export function paymentDueDefault(today=now()){
 const day=new Date(today+'T12:00:00Z');day.setUTCDate(day.getUTCDate()+2);return day.toISOString().slice(0,10);
}

export function paymentLineHtml(row){
 return i18nHtml`<div class="payment-line" data-payment-line="${esc(row.section)}">
  ${input('Kalem adı','',row.label,'text','data-line-label maxlength="120"')}
  ${input('Tutar (₺)','',row.amountCents==null?'':row.amountCents/100,'text','data-line-amount inputmode="decimal" autocomplete="off"')}
  ${input('Oran (%)','',row.rateBasisPoints==null?'':row.rateBasisPoints/100,'text','data-line-rate inputmode="decimal" autocomplete="off"')}
  <button type="button" class="text-btn" data-payment-line-remove aria-label="Kalemi kaldır">Kaldır</button>
 </div>`;
}

export function paymentRowsHtml(rows){
 return Object.entries(paymentSections).map(([section,title])=>i18nHtml`
  <details class="payment-form-disclosure" data-payment-group="${section}">
   <summary><span>${title}</span><small data-payment-count></small></summary>
   <div class="payment-disclosure-content">
    <div data-payment-section="${section}">${rows.filter(row=>row.section===section).map(paymentLineHtml).join('')}</div>
    <button type="button" class="text-btn" data-payment-line-add="${section}">+ Kalem ekle</button>
   </div>
  </details>`).join('');
}

export function paymentFormHtml(record,channels,channel){
 const r=record||{};
 return i18nHtml`<form class="form-grid manual-payment-form">
  <div class="payment-form-scroll">
   <p class="form-help payment-form-intro">${r.paymentApproval?.at?'Onaylı hakedişin pazaryeri, ödeme durumu, tutarı veya tarihi değişirse yeniden onay gerekir.':'Kayıt müşterinin ekranına ve ödeme takvimine yansır.'}</p>
   <fieldset class="payment-form-section payment-core-fields"><legend>Ödeme bilgileri</legend>
    <div class="payment-input-grid">
     <label>Pazaryeri<select name="channel" required>${options(channels,channel)}</select></label>
     ${input('Planlanan ödeme tarihi','expectedDate',r.expectedDate||paymentDueDefault(),'date','required')}
     ${input('Net hakediş (₺)','expectedCents',r.expectedCents==null?'':r.expectedCents/100,'text','inputmode="decimal" autocomplete="off" required')}
     <label>Ödeme durumu<select name="status">${options([['pending','Ödeme bekleniyor'],['sent','Pazaryeri gönderdi — banka teyidi bekleniyor'],['paid','Aktarım bilgisi girildi — Omnigo onayı bekleniyor']],r.status||'pending')}</select></label>
     <label>Tutarın doğruluğu<select name="verification">${options([['estimated','Tahmini / teyitsiz'],['confirmed','Panel / ekstre ile teyitli']],r.verification||'estimated')}</select></label>
     ${input('Teyit edilen panel / ekstre','verificationSource',r.verificationSource||'','text','maxlength="250"')}
    </div>
    <p class="form-help">Net tutar elle girilir; detay kalemlerinden otomatik hesaplanmaz.</p>
    <div class="payment-paid-fields" data-paid-fields>
     <h3>Hesaba geçiş bilgileri</h3><div class="payment-input-grid">
      ${input('Hesaba geçen tutar (₺)','actualCents',(r.actualCents??r.expectedCents??0)/100,'text','inputmode="decimal" autocomplete="off"')}
      ${input('Hesaba geçiş tarihi','paidDate',r.paidDate||now(),'date',`max="${now()}"`)}
     </div>
    </div>
   </fieldset>
   <details class="payment-form-disclosure" data-payment-metadata>
    <summary><span>Dönem, ekstre ve açıklama</span><small>İsteğe bağlı</small></summary>
    <div class="payment-disclosure-content"><div class="payment-input-grid">
     ${input('Dönem başlangıcı','periodStart',r.periodStart||'','date')}
     ${input('Dönem bitişi','periodEnd',r.periodEnd||'','date')}
     ${input('Ekstre / ödeme referansı','reference',r.reference||'','text','maxlength="160"')}
     <label>KDV durumu<select name="taxBasis">${options([['unspecified','Belirtilmedi'],['included','KDV dahil'],['excluded','KDV hariç'],['mixed','Kalemlere göre değişiyor']],r.taxBasis||'unspecified')}</select></label>
    </div><label>Açıklama<textarea name="notes" maxlength="2000" rows="3">${esc(r.notes||'')}</textarea></label></div>
   </details>
   <section class="payment-optional-lines" aria-label="Kalem detayları">
    <h3>Kalem detayları <small>İsteğe bağlı</small></h3>
    <div data-payment-lines></div>
    <details class="payment-entry-help"><summary>Kalem girişine ilişkin bilgiler</summary><p class="form-help" data-template-note></p></details>
   </section>
  </div>
  <footer class="payment-form-footer">
   <p class="form-error" role="alert"></p>
   <div class="dialog-actions"><button type="button" class="btn" data-cancel>Vazgeç</button><button type="submit" class="btn primary">Kaydet</button></div>
  </footer>
 </form>`;
}
