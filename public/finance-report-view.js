import {contentError,loadingState} from './content-state.js';
import {documentOutputActions} from './document-output-actions.js';
import {currentLocale,html,t,text} from './i18n.js';
import {paginationButtons} from './pagination-controls.js';
import {date,esc,money} from './ui.js';
import {profitabilityRows,profitabilityHeaders} from './profitability-table.js';

const amount = value => Number.isSafeInteger(value)?money(value):'—';
const count = value => Number.isSafeInteger(value)?value.toLocaleString(currentLocale()):'—';
const label = value => esc(typeof value==='string'&&value.trim()?value:'—');
const displayDate = value => /^\d{4}-\d{2}-\d{2}/.test(value||'')?date(value.slice(0,10)):'—';
const lookup = (labels,value,fallback='—') => t(Object.hasOwn(labels,value)?labels[value]:fallback);
const feeLabels = {commission:'Komisyon',service:'Hizmet ve kargo',tax:'Vergi ve KDV',adjustment:'Diğer kesintiler'};
const feeVerification = {confirmed:'Manuel · teyitli',estimated:'Manuel · tahmini / teyitsiz'};
const taxLabels = {included:'KDV dâhil',excluded:'KDV hariç',mixed:'KDV karışık',unspecified:'KDV belirtilmedi'};
const feeDateKinds = {statement:'Ekstre tarihi',expected:'Planlanan ödeme',paid:'Hesaba geçiş',transaction:'İşlem tarihi'};
const refundDateKinds = {payment:'Ödeme tarihi',claim:'Talep tarihi',status:'Durum tarihi',order:'Sipariş tarihi'};
const refundStates = {completed:'Geri ödeme doğrulandı',approved:'İade onaylandı',unknown:'Geri ödeme doğrulanamadı'};
const refundCoverage = {available:'Veri var',partial:'Kısmi veri',unavailable:'Veri yok'};
const officialCoverage = {complete:'Dönem kapsamı tam',partial:'Dönem kapsamı kısmi',unavailable:'Resmî veri yok'};
const feeCoverageHeaders=['Pazaryeri','Kayıt kaynağı','Manuel kayıt','Manuel komisyon','Manuel hizmet ve kargo','Manuel vergi ve KDV','Manuel diğer kesintiler','Manuel toplam','Resmî komisyon'];
const feeItemHeaders=['Pazaryeri','Kesinti kalemi','Tarih / dönem','Tutar / oran','Teyit','Kaynak / referans'];
const refundCoverageHeaders=['Pazaryeri','Kapsam','Kaynak','Aktarılan dönem','Ödeme kanıtı'];
const refundItemHeaders=['Sipariş numarası','Pazaryeri','Tarih','Geri ödenen tutar','Durum','Kaynak'];
const subtotalExclusions = {
  reference_only:'Referans kalemi',missing_amount:'Tutar kaydedilmedi',unverified:'Teyit yok',
  no_statement_period:'Ekstre dönemi kaydedilmedi',partial_period:'Ekstre dönemi filtreyi aşıyor',
  overlap:'Örtüşen dönem',tax_basis:'KDV temeli farklı',separate_source:'Resmî ve manuel tutarlar ayrı',
};

function metric(name,value,detail='') {
  return html`<article><span>${esc(t(name))}</span><strong>${value}</strong>${detail?html`<small>${esc(detail)}</small>`:''}</article>`;
}

function feeMetrics({summary={},officialSummary}={}) {
  return html`<div class="report-metrics finance-metrics">
    ${metric('Manuel komisyon',amount(summary.commissionCents))}
    ${metric('Manuel hizmet ve kargo',amount(summary.serviceCents))}
    ${metric('Manuel vergi ve KDV',amount(summary.taxCents))}
    ${metric('Manuel diğer kesintiler',amount(summary.adjustmentCents))}
    ${metric('Manuel kesintiler toplamı',amount(summary.totalCents),lookup(taxLabels,summary.taxBasis,'KDV belirtilmedi'))}
    ${metric('Resmî pazaryeri komisyonu',amount(officialSummary?.commissionCents),officialSummary?.reconciled===false?t('Kaynak toplamı teyit bekliyor'):lookup(officialCoverage,officialSummary?.coverage,'Resmî veri yok'))}
  </div>`;
}

function refundMetrics({totals={}}={}) {
  return html`<div class="report-metrics finance-metrics">
    ${metric('Doğrulanan geri ödeme tutarı',amount(totals.completedCents))}
    ${metric('Geri ödemesi doğrulanan',count(totals.completedCount))}
    ${metric('İade onaylı',count(totals.approvedCount))}
    ${metric('Geri ödemesi doğrulanamayan',count(totals.unknownCount))}
  </div>`;
}

function table(title,headers,rows,{details=false,placeholder}={}) {
  const empty=placeholder??html`<p class="report-empty" role="status">Seçili filtrelere uyan kayıt yok.</p>`;
  return html`<section class="report-panel finance-report-panel${details?' finance-report-detail':''}">
    <div class="report-section-title"><h2>${esc(t(title))}</h2></div>
    <div class="finance-table-scroll ${details?'finance-table-scroll--details':'finance-table-scroll--coverage'} corporate-table-viewport" tabindex="0" aria-label="${esc(t(title))}" ${details?'data-finance-table':'data-finance-coverage'}>
      <table class="corporate-table" data-copyable-table><thead><tr>${headers.map(header=>html`<th scope="col">${esc(t(header))}</th>`).join('')}</tr></thead>
      <tbody>${rows.join('')}</tbody></table>
      ${rows.length?'':html`<div class="finance-table-placeholder">${empty}</div>`}
    </div>
  </section>`;
}

function feeCoverageTable(channels=[],placeholder) {
  const rows=channels.map(channel=>html`<tr>
    <th scope="row">${label(channel.channel)}</th>
    <td>${channel.recordCount>0?t('Manuel kayıt var'):t('Manuel kayıt yok')}</td>
    <td class="finance-number">${count(channel.recordCount)}</td>
    <td class="finance-number">${amount(channel.commissionCents)}</td>
    <td class="finance-number">${amount(channel.serviceCents)}</td>
    <td class="finance-number">${amount(channel.taxCents)}</td>
    <td class="finance-number">${amount(channel.adjustmentCents)}</td>
    <td class="finance-number">${amount(channel.totalCents)}</td>
    <td class="finance-number">${amount(channel.officialCommissionCents)}
      <small>${channel.officialReconciled===false?t('Kaynak toplamı teyit bekliyor'):lookup(officialCoverage,channel.officialCoverage,'Resmî veri yok')}</small>
    </td>
  </tr>`);
  return table('Pazaryeri bazında kesintiler',feeCoverageHeaders,rows,{placeholder});
}

function feeItemTable(items=[],placeholder) {
  const rows=items.map(item=>{
    const official=item.source==='marketplace_api';
    const confirmed=item.verification==='confirmed';
    return html`<tr>
    <th scope="row">${label(item.channel)}</th>
    <td>${label(item.label)}<small>${lookup(feeLabels,item.section)}</small></td>
    <td>${displayDate(item.date)}<small>${lookup(feeDateKinds,item.dateBasis)}</small>
      ${item.periodStart||item.periodEnd?html`<small>${displayDate(item.periodStart)} – ${displayDate(item.periodEnd)}</small>`:''}
    </td>
    <td class="finance-number">${amount(item.amountCents)}
      <small>${lookup(taxLabels,item.taxBasis,'KDV belirtilmedi')}</small>
      ${Number.isSafeInteger(item.rateBasisPoints)?html`<small>${new Intl.NumberFormat(currentLocale(),{style:'percent',maximumFractionDigits:2}).format(item.rateBasisPoints/10000)}</small>`:''}
    </td>
    <td><span class="payment-state ${confirmed?'confirmed':''}">${official?t(confirmed?'Resmî API':'Resmî API · Toplam teyit bekliyor'):lookup(feeVerification,item.verification,'Manuel kayıt')}</span>
      ${!official&&item.includedInSubtotal===false?html`<small>${lookup(subtotalExclusions,item.subtotalExclusion,'Manuel toplama dâhil değil')}</small>`:''}
    </td>
    <td>${label(item.sourceLabel)}${item.verificationSource&&item.verificationSource!==item.sourceLabel?html`<small>${label(item.verificationSource)}</small>`:''}
      ${item.reference?html`<small>${label(item.reference)}</small>`:''}
    </td>
  </tr>`;
  });
  return table('Kesinti kayıtları',feeItemHeaders,rows,{details:true,placeholder});
}

function refundCoverageTable(coverage=[],placeholder) {
  const rows=coverage.map(item=>html`<tr>
    <th scope="row">${label(item.channel)}</th>
    <td><span class="payment-state ${item.state==='available'?'confirmed':''}">${lookup(refundCoverage,item.state,'Veri yok')}</span>
      ${item.undatedCount>0?html`<small>${esc(text`${count(item.undatedCount)} kayıtta tarih doğrulanamadı`)}</small>`:''}
    </td>
    <td>${label(item.source)}</td>
    <td>${item.from||item.to?html`${displayDate(item.from)} – ${displayDate(item.to)}`:'—'}</td>
    <td>${item.paymentEvidence?t('Ödeme kanıtı var'):t('Ödeme kanıtı yok')}</td>
  </tr>`);
  return table('Pazaryerlerine göre veri kapsamı',refundCoverageHeaders,rows,{placeholder});
}

function refundItemTable(items=[],placeholder) {
  const rows=items.map(item=>html`<tr>
    <th scope="row">${label(item.orderNumber)}</th>
    <td>${label(item.channel)}</td>
    <td>${item.eventDate?displayDate(item.eventDate):t('Tarih doğrulanamadı')}
      ${item.dateKind?html`<small>${lookup(refundDateKinds,item.dateKind)}</small>`:''}
    </td>
    <td class="finance-number">${item.state==='completed'?amount(item.amountCents):'—'}</td>
    <td><span class="payment-state ${item.state==='completed'?'confirmed':''}">${lookup(refundStates,item.state,'Geri ödeme doğrulanamadı')}</span>
      ${item.statusLabel?html`<small>${label(item.statusLabel)}</small>`:''}
    </td>
    <td>${label(item.source)}</td>
  </tr>`);
  return table('İade kayıtları',refundItemHeaders,rows,{details:true,placeholder});
}

function reportPager({page,pageSize},total,{fixedSize=false}={}) {
  const pending=total===undefined,pages=Math.max(1,Math.ceil((total??0)/pageSize)),position=page-1;
  // Shared buttons use zero-based data attributes. Report endpoints use one-based pages.
  const numbered=paginationButtons({page:position,count:pages,attribute:'data-finance-index',disabled:pending});
  return html`<nav class="finance-report-pager" aria-label="Rapor tablosu sayfaları">
    ${fixedSize?'':html`<label>Sayfa başına kayıt<select data-finance-size ${pending?'disabled':''}>
      ${[10,20,50].map(size=>html`<option value="${size}" ${size===pageSize?'selected':''}>${size}</option>`).join('')}
    </select></label>`}
    <span>${pending?'—':esc(text`${count(total)} kayıt`)}</span>
    <div class="finance-page-buttons">
      <button type="button" class="btn" data-finance-page="${page-1}" ${page<=1?'disabled':''}>Önceki</button>
      ${numbered}
      <button type="button" class="btn" data-finance-page="${page+1}" ${page>=pages?'disabled':''}>Sonraki</button>
    </div>
  </nav>`;
}

export function financeReportResult(view,report,model,companyName='',error='') {
  const refund=view==='financeRefunds';
  const profit=view==='financeProfitability';
  const notes={
    financeRefunds:'Geri ödeme tutarları ödeme kanıtı bulunan kayıtlardan alınır. Tarihi doğrulanamayan kayıtlar dönem toplamlarına girmez.',
  };
  const detailsPlaceholder=report?undefined:error
    ?html`${contentError(error)}<button class="btn" type="button" data-finance-retry>Tekrar dene</button>`
    :loadingState('Rapor hazırlanıyor…',{compact:true});
  const coveragePlaceholder=report?undefined:'';
  const range=report??model.filters;
  const output=value=>({attr:'data-profit-output',value,disabled:!report?.total});
  return html`<div class="report-caption"><strong>${displayDate(range?.from)} – ${displayDate(range?.to)}</strong>
      ${companyName?html`<span>${label(companyName)}</span>`:''}
      ${profit&&model.canExport!==false?documentOutputActions({excel:output('excel'),pdf:output('pdf'),print:output('print'),share:output('share')}):''}
    </div>
    ${notes[view]?html`<p class="report-warning">${esc(t(notes[view]))}</p>`:''}
    ${profit?html`
      ${table('Sipariş bazında satış ve kesintiler',profitabilityHeaders,profitabilityRows(report?.rows),{details:true,placeholder:detailsPlaceholder})}
      ${reportPager(model,report?.total,{fixedSize:true})}
    `:html`
    ${refund?refundMetrics(report??undefined):feeMetrics(report??undefined)}
    ${refund?refundCoverageTable(report?.coverage,coveragePlaceholder):feeCoverageTable(report?.channels,coveragePlaceholder)}
    ${refund?refundItemTable(report?.rows,detailsPlaceholder):feeItemTable(report?.items,detailsPlaceholder)}
    ${reportPager(model,refund?report?.total:report?.totalItems)}`}`;
}
