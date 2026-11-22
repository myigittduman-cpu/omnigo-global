import {rawTurnoverValue,salesTurnoverVisible} from './sales-turnover.js';
import {html,t,currentLocale} from './i18n.js';
import {esc,money,platform} from './ui.js';
import {summaryChart} from './sales-summary-chart.js';

/** Presentation only: use already-loaded daily values; missing days remain unknown. */
export function overviewDailyData(data){
 const amounts=salesTurnoverVisible(data),metric=amounts?'amountCents':'quantity';
 const valueOf=amounts?rawTurnoverValue:day=>day&&Object.hasOwn(day,'orderedQuantity')?day.orderedQuantity:day?.quantity;
 const active=new Set(data.channels.filter(channel=>channel.status==='active').map(channel=>channel.name));
 const items=(data.marketplaceSales?.channels||[]).filter(item=>active.has(item.channel)&&item.available&&Array.isArray(item.days)&&item.days.some(day=>Number.isFinite(valueOf(day))));
 const dates=[...new Set(items.flatMap(item=>item.days.map(day=>day.date)))].sort();
 const series=items.map(item=>new Map(item.days.map(day=>[day.date,day])));
 const days=dates.map(date=>{
  const values=series.map(rows=>valueOf(rows.get(date)));
  return {date,[metric]:values.every(Number.isFinite)?values.reduce((sum,value)=>sum+value,0):null};
 });
 return {days,known:items.length,total:active.size};
}
function distribution(state,amounts){
 const rows=state.data.channels.filter(channel=>channel.status==='active').map(channel=>{
  const sale=state.data.sales.find(item=>item.channel===channel.name&&item.month===state.month);
  const value=amounts?rawTurnoverValue(sale):sale?.orders;
  return {name:channel.name,value:Number.isFinite(value)?value:null};
 });
 const max=Math.max(1,...rows.map(row=>Math.abs(row.value||0)));
 return rows.length?html`<div class="balanced-market-bars">${rows.map(row=>html`<div class="balanced-market-row">${platform(row.name)}<span class="balanced-market-track"><span class="${row.value<0?'negative':''}" style="width:${Math.abs(row.value||0)/max*100}%"></span></span><span class="balanced-market-value">${row.value===null?t('Veri bekleniyor'):amounts?money(row.value):row.value.toLocaleString(currentLocale())}</span></div>`).join('')}</div>`:html`<p class="report-empty">Pazaryeri satış verisi bekleniyor.</p>`;
}
export function overviewCharts(state){
 const daily=overviewDailyData(state.data),amounts=salesTurnoverVisible(state.data);
 const chart=summaryChart(daily.days,amounts?'amount':'quantity',{displayBasis:'turnover',turnoverDisplayBasis:state.data.turnoverDisplayBasis||'before_cancellations',chartLabel:t('Seçili dönem')}).replace('<svg ','<svg preserveAspectRatio="none" ');
 return html`<div class="balanced-overview-charts"><section class="panel balanced-overview-chart"><div class="balanced-overview-heading"><h2>${t(amounts?'Günlük satış':'Günlük satış adedi')}</h2><small>${daily.known?daily.known+' / '+daily.total+' '+t('pazaryerinde günlük veri'):t('Günlük veri bekleniyor')}</small></div>${chart}<p class="panel-note">Eksik günler boş bırakılır. Grafik, aktarılmış günlük kayıtları gösterir.</p></section><section class="panel balanced-overview-distribution"><div class="balanced-overview-heading"><h2>${t(amounts?'Satış dağılımı':'Pazaryeri sipariş sayıları')}</h2><small>Seçili dönem</small></div>${distribution(state,amounts)}</section></div>`;
}
export function overviewSalesLayout(state,cards,table){return overviewCharts(state)+html`<div class="overview-sales-layout"><section class="overview-period-details"><h2 class="overview-period-title">Pazaryeri dönem özetleri ve ayrıntıları</h2>${cards}</section>${table}</div>`;}
