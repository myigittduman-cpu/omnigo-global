import {html} from './i18n.js';
import {esc,money,date} from './ui.js';
import {paymentLabel} from './payment-records.js';
import {paymentCalendarEvents} from './payment-calendar-events.js';
import {editablePayment,paymentSelectionInput,paymentSelectionToolbar} from './payment-selection.js';
import {receivedPaymentNumbers} from './received-payments-view.js';

export const paymentDayItems=(report,day)=>paymentCalendarEvents(report).filter(item=>item.calendarDate===day);
export function paymentDayPage(rows,requested=1){
 const totalPages=Math.max(1,Math.ceil(rows.length/3));
 const page=Math.max(1,Math.min(totalPages,Math.trunc(Number(requested))||1));
 return {page,totalPages,rows:rows.slice((page-1)*3,page*3)};
}

function paymentDayRow(item,canManage,selection){
 const editable=canManage&&editablePayment(item),paid=item.status==='paid';
 return html`<tr data-day-payment-row="${esc(item.id)}">
  ${selection?.mode?html`<td class="payment-day-select-cell">${paymentSelectionInput(item,selection)}</td>`:''}
  <th scope="row">${esc(item.channel)}</th>
  <td class="payment-day-amount">${money(item.expectedCents)}</td>
  <td>${date(item.expectedDate)}</td>
  <td><span class="payment-state ${paid?'sent':item.verification||'estimated'}">${esc(paymentLabel(item))}</span></td>
  <td class="payment-day-amount">${paid?html`${money(item.actualCents)}<small>${date(item.paidDate)}</small>`:'—'}</td>
  <td><div class="payment-record-actions">
   ${item.forecastReadOnly?html`<small>${item.forecastSource==='marketplace'?'Pazaryeri API’si':item.forecastSource==='marketplace_panel'?'Pazaryeri paneli':'Manuel kayıt'}</small>`:html`<button class="btn" type="button" data-day-payment-detail="${item.id}">İncele</button>`}
   ${editable?html`<button class="btn" type="button" data-day-payment-edit="${item.id}">Düzenle</button>`:''}
   ${editable&&!item.paymentApproval?.at?html`<button class="btn" type="button" data-day-payment-receipt="${item.id}">${paid?'Ödeme bilgisi':'Ödeme bilgisi gir'}</button>`:''}
  </div></td>
 </tr>`;
}

export function paymentDayView(rows,canManage=false,selection=null,requestedPage=1){
 const visible=paymentDayPage(rows,requestedPage),numbers=receivedPaymentNumbers(visible.page,visible.totalPages);
 return html`<section class="payment-day-details">
  ${canManage?html`<div class="payment-day-toolbar">${paymentSelectionToolbar(rows,selection,{showCount:false})}${selection?.mode?'':html('<button class="btn" type="button" data-day-payment-add>Hakediş ekle</button>')}</div>`:''}
  ${rows.length?html`<div class="payment-day-table-wrap corporate-table-viewport" tabindex="0" aria-label="Günün hakediş tablosu">
   <table class="corporate-table payment-day-table"><thead><tr>
    ${selection?.mode?html('<th scope="col" class="payment-day-select-cell">Seç</th>'):''}
    <th scope="col">Pazaryeri</th><th scope="col">Net hakediş</th><th scope="col">Planlanan ödeme</th>
    <th scope="col">Durum</th><th scope="col">Bildirilen tutar</th><th scope="col">İşlemler</th>
   </tr></thead><tbody>${visible.rows.map(item=>paymentDayRow(item,canManage,selection)).join('')}</tbody></table>
  </div>`:html('<p class="payment-day-empty">Bu tarihte kayıtlı hakediş bulunmuyor.</p>')}
  <footer class="payment-day-pages">
   ${selection?.mode?html`<span role="status">${selection.items.size} seçili</span>`:''}
   ${visible.totalPages>1?html`<span role="status">Sayfa ${visible.page} / ${visible.totalPages}</span><nav aria-label="Günün hakediş sayfaları">
    ${numbers.map((page,index)=>html`${index&&page-numbers[index-1]>1?'<span aria-hidden="true">…</span>':''}<button class="btn" type="button" data-day-payment-page="${page}" aria-label="${page}. sayfa" ${page===visible.page?'aria-current="page"':''}>${page}</button>`).join('')}
   </nav>`:''}
  </footer>
 </section>`;
}
