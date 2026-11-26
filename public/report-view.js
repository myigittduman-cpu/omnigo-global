import {salesTurnoverVisible,customerTurnover} from './sales-turnover.js';
import {loadingState} from './content-state.js';
import {documentOutputActions} from './document-output-actions.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,money,date,now} from './ui.js';
import {salesChart,chartColors} from './report-chart.js';
import {reportProducts} from './report-products.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {analysisMeasures,analysisDeductionLabel,showAfterCancellationTurnover} from './analysis-metrics.js';
import {analysisPeriodButtons,analysisPeriodRange} from './analysis-periods.js';
export function analysisShell(state){
 const key=state.company+':'+state.month,today=now();
 if(state.reportFilters?.key!==key)state.reportFilters={key,period:'history',...analysisPeriodRange('history',today),channel:'all',hidden:[]};
 const f=state.reportFilters;
 if(!marketplaceVisible(f.channel))f.channel='all';
 return i18nHtml`<section class="reporting" data-reporting="analytics"><form class="report-filter"><label>Başlangıç<input type="date" name="from" value="${f.from}" required></label><label>Bitiş<input type="date" name="to" value="${f.to}" required></label><button class="btn primary" type="submit">Filtrele</button><label>Pazaryeri<select name="channel"><option value="all">Tüm pazaryerleri</option>${state.data.channels.map(c=>i18nHtml`<option ${f.channel===c.name?'selected':''} value="${esc(c.name)}">${esc(c.name)}</option>`).join('')}</select></label></form>
 <nav class="analysis-period" aria-label="Rapor dönemleri">${analysisPeriodButtons(f.period,today)}</nav>
 <div class="report-result" aria-live="polite">${loadingState('Rapor hazırlanıyor…')}</div></section>`;
}
export function comparisonLabel(current,previous){
 if(previous==null||current==null)return i18nT('Karşılaştırma verisi yok');
 if(previous===0)return current===0?i18nT('Değişim yok'):i18nT('Önceki dönem sıfır; yüzde hesaplanmaz');
 const change=(current-previous)/Math.abs(previous)*100;
 return i18nText`${change>0?'+':''}${new Intl.NumberFormat(currentLocale(),{maximumFractionDigits:1}).format(change)}% · önceki döneme göre`;
}
export function analysisOutputActions(){
 return i18nHtml`<div class="report-downloads">${documentOutputActions({
  excel:{attr:'data-export',value:'xlsx'},pdf:{attr:'data-export',value:'pdf'},share:{attr:'data-report-share'},print:{attr:'data-report-print'},
 })}</div>`;
}
export function analysisResult(report,hidden=[]){
 const amounts=salesTurnoverVisible(report);
 const deduction=analysisDeductionLabel(report),values=analysisMeasures(report.totals,report.refundsVerified,report.sensitiveVisibility);
 const metric=(name,value,format=money)=>i18nHtml`<div class="analysis-metric-value"><span>${i18nT(name)}</span><strong>${value===null?'—':format(value)}</strong></div>`;
 const count=value=>value.toLocaleString(currentLocale());
 const chart=amounts?i18nHtml`<section class="report-panel analysis-chart" aria-labelledby="analysis-chart-title"><div class="report-section-title"><h2 id="analysis-chart-title">Satış grafiği</h2><span>${i18nT(!report.hideMarketplaceSalesCancellations&&report.turnoverDisplayBasis==='after_cancellations'?'Günlük iptal sonrası ciro':'Günlük ciro')}</span></div><div class="chart-legend">${report.series.map((s,i)=>i18nHtml`<label><input type="checkbox" data-chart-channel="${esc(s.channel)}" ${hidden.includes(s.channel)?'':'checked'} ${s.available?'':'disabled'}><span class="chart-dot color-${i%chartColors.length}"></span>${esc(s.channel)}${s.available?'':' · veri yok'}</label>`).join('')}</div><div data-chart>${salesChart(report.series,hidden)}</div></section>`:'';
 const summary=i18nHtml`<div class="report-metrics analysis-metrics" data-analysis-amounts="${amounts}">
  ${amounts?i18nHtml`<article class="analysis-turnover-pair">${metric(!report.hideMarketplaceSalesCancellations&&report.turnoverDisplayBasis==='after_cancellations'?'İptal sonrası ciro':'Ciro',values.turnover)}${showAfterCancellationTurnover(report)?metric('İptaller Sonrası Ciro',values.afterCancellations):''}</article>`:''}
  <article>${metric('Sipariş sayısı',values.orders,count)}</article>
  ${amounts?i18nHtml`<article>${metric('Sipariş Başına Satış',values.average)}</article>`:''}
  ${deduction?i18nHtml`<article class="analysis-metric-wide">${metric(deduction,values.deductions)}</article>`:''}
  <article class="analysis-metric-wide${deduction?'':' analysis-metric-full'}">${metric('Satılan ürün adedi',values.units,count)}</article>
 </div>`;
 const notes=report.notes.filter(note=>!note.endsWith(': seçilen dönemin tamamı henüz aktarılmadı.'));
 const incomplete=report.series.some(series=>series.days.some(day=>day.coverageLimited));
 const preset=analysisPeriodRange('history');
 const range=report.from===preset.from&&report.to===preset.to?'':i18nHtml`<p class="report-note">Seçili dönem ${date(report.from)} – ${date(report.to)}</p>`;
 return i18nHtml`${range}${incomplete?i18nHtml('<p class="report-note">Toplamlar doğrulanmış kayıtları içerir.</p>'):''}<p class="report-export-status" role="status"></p>${summary}${notes.map(n=>i18nHtml`<p class="report-warning">${esc(n)}</p>`).join('')}<section class="report-panel report-products" data-report-products>${reportProducts(report.products,{amounts,gross:customerTurnover(report),neutral:report.hideMarketplaceSalesCancellations,adjusted:report.turnoverDisplayBasis==='after_cancellations',afterCancellation:customerTurnover(report)&&showAfterCancellationTurnover(report)})}</section>${chart}`;
}

