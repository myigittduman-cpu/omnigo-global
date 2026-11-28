import {loadingState} from './content-state.js';
import {documentOutputActions} from './document-output-actions.js';
import {html,t,text} from './i18n.js';
import {esc,icon,money,openDialog,platform} from './ui.js';
import {companyLogoSource,exportCompanyName} from './company-export-brand.js';
import {paginationButtons} from './pagination-controls.js';
import {upcomingPayoutReview} from './upcoming-payout-review.js';
import {paymentNoticeButton} from './payment-records.js';
import {calendarMonths} from './calendar-date.js';
import {upcomingMarketplaceEntries,upcomingPaymentDate,upcomingPaymentExpectedCents,upcomingPaymentModel,
 upcomingPaymentTableRows,upcomingPaymentTitle} from './upcoming-payment-model.js';

const paymentArrow='<span class="upcoming-payment-flow-arrow" aria-hidden="true"><svg viewBox="0 0 10 12"><path d="M3 2.5 7 6l-4 3.5"/></svg></span>';
const waitingIcon='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

function paymentGroup(market,editing,selected,canManage,manualRecords,manualForecast){
 const entries=upcomingMarketplaceEntries(market);
 const selection=editing&&entries.length?html`<input type="checkbox" data-upcoming-market-selection value="${esc(market.name)}" aria-label="${esc(text`${market.name} belgeye eklensin`)}" ${selected?'checked':''}>`:'';
 const marketHeader=html`<th scope="rowgroup" class="upcoming-payments-market" rowspan="${entries.length||1}">
  <div class="upcoming-market-identity">${selection}
   ${platform(market.name)}
   ${manualForecast?html`<small class="upcoming-market-manual" title="Hakediş tahmini elle girilir">Manuel</small>`:''}
  </div>
 </th>`;
 return html`<tbody class="upcoming-payments-group">
  ${entries.length?entries.map((item,index)=>html`<tr class="upcoming-payment-entry"${item.paymentApproval?.at?' data-payment-received':''}>
   ${index===0?marketHeader:''}
   <td class="upcoming-payment-market-arrow" aria-hidden="true">${paymentArrow}</td>
   <td class="upcoming-payments-date"><time datetime="${esc(item.date)}">${esc(upcomingPaymentDate(item.date))}</time></td>
   <td class="upcoming-payment-arrow" aria-hidden="true">${paymentArrow}</td>
   <td class="upcoming-payments-value">
    <div class="upcoming-payment-value-line">
    <span class="payment-amount-line">
    <strong class="upcoming-payments-amount" ${item.adjusted?html`title="Omnigo düzeltmesi"`:item.manualAmountApproval?html`title="Hakediş tutarı yönetici tarafından manuel onaylandı"`:item.source==='manual'?html`title="Elle girildi"`:''}>${money(item.amountCents)}</strong>
    ${item.paymentApproval?.at?html`<span class="payment-approved-check" role="img" aria-label="Hesaba geçtiği yönetici tarafından onaylandı" title="Hesaba geçtiği yönetici tarafından onaylandı">✓</span>`:''}
    </span>
    ${canManage&&(item.adjustmentRevision||item.source==='manual'&&manualRecords.has(item.id))?html`<span class="upcoming-row-actions">
      <button class="btn" type="button" data-upcoming-approve="${esc(item.id)}">Manuel onayla</button>
      <button class="btn" type="button" data-upcoming-record="${esc(item.id)}">Düzenle</button>
      <button class="btn" type="button" data-upcoming-record="${esc(item.id)}" data-upcoming-remove>Sil</button>
    </span>`:''}
    </div>
   </td>
  </tr>`).join(''):html`<tr class="upcoming-payment-empty-row">
   ${marketHeader}
   <td class="upcoming-payment-market-arrow" aria-hidden="true"></td>
   <td class="upcoming-payments-date">—<span class="screen-reader">Yaklaşan ödeme kaydı yok</span></td>
   <td class="upcoming-payment-arrow" aria-hidden="true"></td>
   <td class="upcoming-payments-value"><strong class="upcoming-payments-amount">${money(0)}</strong></td>
  </tr>`}
 </tbody>`;
}

function periodNavigation(model,selectingMonths){
 if(model.pages<2||selectingMonths)return '';
 const page=model.page-1;
 return html`<nav class="upcoming-payment-pages" aria-label="Hakediş dönemleri">
  <button class="btn" type="button" data-upcoming-page="${page-1}" ${page===0?'disabled':''}>Önceki</button>
  ${paginationButtons({page,count:model.pages,attribute:'data-upcoming-page'})}
  <button class="btn" type="button" data-upcoming-page="${page+1}" ${page+1>=model.pages?'disabled':''}>Sonraki</button>
 </nav>`;
}

export function upcomingPayments(overview,channel='all',marketplaces=[],company=null,selectedStart='',{editing=false,excluded=new Set(),selectedMonths=[],canManage=true,canExport=canManage,model:visibleModel}={}){
 const selectingMonths=selectedMonths.length>0;
 const model=visibleModel??upcomingPaymentModel(overview,channel,marketplaces,selectedStart,new Set(),selectedMonths);
 const tableRows=upcomingPaymentTableRows(model,channel);
 const pendingChanges=canManage?(overview?.pendingChanges??[]).filter(change=>
  tableRows.some(market=>market.name===change.channel&&market.payments.has(change.date))):[];
 const exportOnly=Boolean(overview?.periods);
 const exportModel=excluded.size||exportOnly&&selectingMonths
  ?upcomingPaymentModel(overview,channel,marketplaces,model.period?.start??selectedStart,excluded,selectedMonths):model;
 const manualRecords=new Set((overview?.manualRecords??[]).map(record=>record.id));
 const manualForecastChannels=new Set(overview?.manualForecastChannels??[]);
 const title=upcomingPaymentTitle(company);
 const disabled=!exportModel.dates.length;
 return html`<section class="report-panel upcoming-payments" aria-labelledby="upcoming-payments-title" ${selectingMonths?'data-upcoming-combined':''}>
  <div class="upcoming-payments-table-frame upcoming-forecast-scroll" role="region" tabindex="0" aria-labelledby="upcoming-payments-title">
  <div class="report-section-title">
   <div class="upcoming-payments-identity">
    ${company?.hasLogo&&Number.isSafeInteger(Number(company.id))&&Number(company.id)>0?html`<span class="upcoming-payments-company-logo" data-company-logo="${Number(company.id)}"><img src="${companyLogoSource(company,{payment:true})}&v=${Number(company.brandingVersion)||0}" alt="${esc(text`${exportCompanyName(company)} logosu`)}"></span>`:''}
    <div><h2 id="upcoming-payments-title">${esc(title)}</h2>
     ${model.period?html`<small class="upcoming-payment-range" aria-live="polite">
      <span>Başlangıç tarihi <time datetime="${model.period.start}">${upcomingPaymentDate(model.period.start)}</time></span>
      <span>Bitiş tarihi <time datetime="${model.period.end}">${upcomingPaymentDate(model.period.end)}</time></span>
     </small>`:''}
    </div>
   </div>
   ${canManage||canExport?html`<div class="upcoming-payments-actions">
    ${canManage&&overview?.manualChannels?.length?html`<button type="button" class="btn" data-upcoming-manual>${icon('plus')} Hakediş gir</button>`:''}
    ${canManage&&overview?paymentNoticeButton():''}
    ${canExport?html`<button type="button" class="btn" data-upcoming-edit aria-pressed="${editing}" ${model.dates.length||editing?'':'disabled'}>${editing?t('Bitti'):t('Düzenle')}</button>
    ${documentOutputActions({excel:{attr:'data-upcoming-excel',disabled},pdf:{attr:'data-upcoming-download',disabled},share:{attr:'data-upcoming-share',disabled},print:{attr:'data-upcoming-print',disabled}})}`:''}
   </div>`:''}
  </div>
  ${overview&&tableRows.length?html`<div class="upcoming-payment-table-scroll corporate-table-viewport" role="region" aria-label="Pazaryeri ödemeleri tablosu" tabindex="0"><table class="corporate-table" data-table-fit aria-labelledby="upcoming-payments-title">
    <colgroup><col class="upcoming-market-col"><col class="upcoming-arrow-col"><col class="upcoming-date-col"><col class="upcoming-amount-arrow-col"><col></colgroup>
    <thead class="upcoming-payments-column-labels"><tr>
     <th scope="col" colspan="2">Pazaryeri</th><th scope="col" colspan="2">Ödeme tarihi</th><th scope="col">Tutar</th>
    </tr></thead>
    ${tableRows.map(market=>paymentGroup(market,editing,!excluded.has(market.name),canManage,manualRecords,manualForecastChannels.has(market.name))).join('')}
   </table></div>`:''}
  <div class="upcoming-payments-empty" role="status">${!overview
   ?loadingState('Hakedişler hazırlanıyor…',{compact:true})
   :!model.dates.length?t('Yaklaşan hakediş kaydı bulunmuyor.') : ''}</div>
  <div class="upcoming-payment-footer">
   ${periodSelectionActions(exportOnly?exportModel:model,selectingMonths,exportOnly)}
   ${periodNavigation(model,selectingMonths&&!exportOnly)}
   <span class="upcoming-payments-file-status" data-upcoming-file-status role="status"></span>
  </div>
  ${upcomingPayoutReview(pendingChanges)}
  </div>
  <dl class="upcoming-payments-total">
   <div><dt>${waitingIcon}<span>${exportOnly?t('Beklenen toplam hakediş'):t('Beklenen tahmini hakediş')}</span></dt>
    <dd>${paymentArrow}<strong>${overview?money(upcomingPaymentExpectedCents(model)):'—'}</strong></dd></div>
   ${(excluded.size||exportOnly&&selectingMonths)&&overview?html`<div><dt>Belge toplamı</dt><dd>${paymentArrow}<strong>${money(upcomingPaymentExpectedCents(exportModel))}</strong></dd></div>`:''}
  </dl>
 </section>`;
}

function periodSelectionActions(model,selectingMonths,exportOnly){
 if(!model.months.length&&!selectingMonths)return '';
 return html`<div class="upcoming-period-controls">
  ${model.months.length?html`<button class="btn" type="button" data-upcoming-period-picker>${exportOnly?t('Belge dönemi seç'):selectingMonths?t('Dönemleri değiştir'):t('Dönem seç')}</button>`:''}
  ${selectingMonths?html`<button class="btn" type="button" data-upcoming-period-clear>${exportOnly?t('Belge seçimini temizle'):t('Tek döneme dön')}</button>
   <span data-upcoming-selection-label>${model.selectedMonths.length?text`${model.selectedMonths.length} seçili ay`:t('Seçili dönemler bulunamadı.')}</span>`:''}
 </div>`;
}

export function upcomingMonthOptions(months,selectedMonths=[]){
 const selected=new Set(selectedMonths);
 const years=new Map();
 for(const entry of months){
  const year=entry.month.slice(0,4);
  if(!years.has(year))years.set(year,[]);
  years.get(year).push(entry);
 }
 return [...years].map(([year,entries])=>html`<fieldset class="upcoming-period-year">
  <legend>${year}</legend>
  ${entries.map(({month,items})=>html`<label class="upcoming-period-option">
   <input type="checkbox" name="period" value="${month}" data-upcoming-month="${month}" ${selected.has(month)?'checked':''}>
   <strong>${esc(calendarMonths[Number(month.slice(5))-1])} ${year}</strong>
   <span>${text`${items.length} kayıt`}</span>
  </label>`).join('')}
 </fieldset>`).join('');
}

export function openUpcomingPeriodPicker({months,selectedMonths,current,onApply}){
 if(!months.length||!current())return;
 const dialog=openDialog(t('Hakediş dönemleri'),html`<form class="upcoming-period-form" data-upcoming-period-form>
  <div class="upcoming-period-options" role="group" aria-label="Gösterilecek dönemler">
   ${upcomingMonthOptions(months,selectedMonths)}
  </div>
  <div class="upcoming-period-buttons">
   <button class="btn" type="button" data-upcoming-period-cancel>Vazgeç</button>
   <button class="btn primary" type="submit" data-upcoming-period-show>Seçili dönemleri göster</button>
  </div>
 </form>`,{className:'upcoming-period-dialog'});
 const form=dialog.querySelector('[data-upcoming-period-form]');
 const submit=form.querySelector('[data-upcoming-period-show]');
 const sync=()=>{submit.disabled=!form.querySelector('input[name="period"]:checked');};
 form.addEventListener('change',sync);sync();
 form.querySelector('[data-upcoming-period-cancel]').onclick=()=>dialog.close();
 form.onsubmit=event=>{
  event.preventDefault();
  if(!current()||submit.disabled)return;
  const values=new FormData(form).getAll('period');
  if(!values.length)return;
  dialog.addEventListener('close',()=>{if(current())onApply(values);},{once:true});
  dialog.close();
 };
}
