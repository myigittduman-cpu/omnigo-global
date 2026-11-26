import {html,t} from './i18n.js';
import {esc,money,date} from './ui.js';
import {receivedPaymentPage,paymentListItems} from './received-payments-data.js';
import {editablePayment,paymentSelectionToolbar,paymentSelectionInput} from './payment-selection.js';
import {paymentMarketplaceFilter} from './payment-marketplace-filter.js';
import {paymentDifferenceNote} from './payment-difference-note.js';
import {paymentApprovable,paymentIsApproved} from './payment-approval.js';
import {paymentStatusMarkup} from './payment-records.js';
import {paginationNumbers} from './pagination-controls.js';
import {canRemoveCalendarPayment} from './upcoming-payment-adjustment.js';

export function receivedPaymentNumbers(page,totalPages){
 return paginationNumbers(page-1,totalPages).map(number=>number+1);
}

export function paymentListTabs(canManage,notice,periodView=false){
 if(notice)return [['reported',periodView?'Ödeme onayı bekleyen':'Ödeme Beklenen'],['received','Hesaba Geçen']];
 const tabs=[['pending','Beklenen Ödemeler'],['received','Hesaba Geçen']];
 if(canManage)tabs.push(['calendar','Takvim Kayıtları']);
 return tabs;
}

const forecastSources={marketplace:'Pazaryeri API’si',marketplace_panel:'Pazaryeri paneli'};
function paymentActions(item,canManage){
 const remove=canManage&&canRemoveCalendarPayment(item)?html`<button class="btn" type="button" data-received-remove="${esc(item.id)}">Sil</button>`:'';
 if(item.forecastReadOnly)return html`<div class="received-payment-actions"><small class="received-payment-source">${t(forecastSources[item.forecastSource]||'Manuel kayıt')}</small>${remove}</div>`;
 const approved=paymentIsApproved(item),editable=canManage&&editablePayment(item);
 return html`<div class="received-payment-actions">
  <button class="btn" type="button" data-received-detail="${Number(item.id)}">İncele</button>
  ${editable?html`<button class="btn" type="button" data-received-edit="${item.id}">Düzenle</button>`:''}
  ${editable&&!approved?html`<button class="btn" type="button" data-received-receipt="${item.id}">${item.status==='paid'?'Ödeme bilgisi':'Ödeme bilgisi gir'}</button>`:''}
  ${canManage&&paymentApprovable(item)?html`<button class="btn primary" type="button" data-received-approve="${item.id}">Ödeme onayı</button>`:''}
  ${remove}
 </div>`;
}

function paymentRow(item,canManage,received,selection){
 const reported=item.status==='paid';
 const source=reported?'':item.verificationSource;
 return html`<tr class="${paymentIsApproved(item)?'payment-approved-row':''}" data-received-payment="${esc(item.id)}">
  ${selection?.mode?html`<td class="payment-select-cell">${paymentSelectionInput(item,selection)}</td>`:''}
  <td>${date(received?item.paidDate:item.expectedDate)}${!received&&reported?html`<small>Aktarım: ${date(item.paidDate)}</small>`:''}</td>
  <td>${esc(item.channel)}</td><td>${money(item.expectedCents)}${paymentDifferenceNote(item)}</td>
  <td><strong>${reported?money(item.actualCents):'—'}</strong></td>
  <td>${paymentStatusMarkup(item)}${source?html`<small>${esc(source)}</small>`:''}</td>
  <td>${paymentActions(item,canManage)}</td></tr>`;
}

export function receivedPaymentsView(report,requestedPage=1,canManage=false,tab='received',selection=null,{notice=false,back=false}={}){
 const received=tab==='received',data=receivedPaymentPage(report,requestedPage,tab),pages=receivedPaymentNumbers(data.page,data.totalPages);
 const toolbar=canManage?paymentSelectionToolbar(paymentListItems(report,tab),selection):'';
 const filter=paymentMarketplaceFilter(report);
 const tabs=paymentListTabs(canManage,notice,report.periodView);
 const empty=received?'Seçili dönemde onaylanmış ödeme bulunmuyor.':tab==='calendar'?'Seçili takvimde hakediş bulunmuyor.':
  tab==='reported'?'Seçili dönemde onay bekleyen ödeme bildirimi bulunmuyor.':'Seçili dönemde beklenen ödeme bulunmuyor.';
 return html`<section class="received-payments" aria-label="${esc(t(notice?'Ödendi Bildirimi':'Ödeme detayları'))}">
  ${back?html`<div><button class="btn" type="button" data-received-back>← ${t('Günün hakedişlerine dön')}</button></div>`:''}
  <nav class="payment-list-tabs" aria-label="Ödeme listeleri">${tabs.map(([key,label])=>html`<button class="btn" type="button" data-payment-list-tab="${key}" aria-pressed="${tab===key}">${t(label)}<span>${paymentListItems(report,key).length}</span></button>`).join('')}</nav>
  <div class="received-payments-caption">
   <p>${esc(date(report.from))} – ${esc(date(report.to))}</p>
   ${notice&&report.periodView?html`<strong>${money(received?report.summary.receivedCents:report.summary.sentCents)}</strong>`:''}
   ${toolbar||filter?html`<div class="received-payments-tools">${toolbar}${filter}</div>`:''}
  </div>
  ${tab==='pending'&&!report.periodView?html('<p class="payment-list-note">Aktarımı bildirilen ödemeler, Omnigo onaylayana kadar bu listede kalır.</p>'):''}
  <div class="received-payments-scroll" tabindex="0" aria-label="Ödeme tablosu"><table class="corporate-table" data-copyable-table><thead><tr>
   ${selection?.mode?html('<th scope="col" class="payment-select-cell">Seç</th>'):''}
   <th scope="col">${received?'Hesaba geçiş':'Planlanan ödeme'}</th><th scope="col">Pazaryeri</th>
   <th scope="col">Net hakediş</th><th scope="col">${received?'Hesaba geçen':'Bildirilen tutar'}</th>
   <th scope="col">Durum</th><th scope="col">İşlemler</th></tr></thead>
   <tbody>${data.items.map(item=>paymentRow(item,canManage,received,selection)).join('')||html`<tr><td colspan="${selection?.mode?7:6}" class="received-payments-empty">${empty}</td></tr>`}</tbody>
  </table></div>
  <footer class="received-payments-footer"><span aria-live="polite">${data.from}–${data.to} / ${data.total} kayıt</span><nav class="received-payments-pages" aria-label="Ödemelerin sayfaları">${pages.map((number,index)=>html`${index&&number-pages[index-1]>1?'<span aria-hidden="true">…</span>':''}<button class="btn" type="button" data-received-page="${number}" aria-label="${number}. sayfa" ${number===data.page?'aria-current="page"':''}>${number}</button>`).join('')}</nav></footer>
 </section>`;
}
