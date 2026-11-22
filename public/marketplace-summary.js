import {rawTurnoverValue,turnoverLabel,turnoverDescription,cancelledTurnoverValue,turnoverValue,turnoverDays,salesTurnoverVisible,customerTurnover} from './sales-turnover.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,money} from './ui.js';
import {summaryChart as financialChart} from './sales-summary-chart.js';
import {bindSalesCardLayout} from './sales-card-layout.js';

const number=value=>new Intl.NumberFormat(currentLocale(),{maximumFractionDigits:0}).format(value);
const day=value=>new Date(value+'T12:00:00+03:00').toLocaleDateString(currentLocale(),{day:'numeric',month:'short',timeZone:'Europe/Istanbul'});
const valueOf=(period,metric,native=false,grossOnly=false)=>period?.available?(metric==='quantity'
 ?(grossOnly&&Object.hasOwn(period,'orderedQuantity')?period.orderedQuantity:native?period.salesQuantity:period.quantity)
 :grossOnly?rawTurnoverValue(period):turnoverValue(period)):null;
const format=(value,metric)=>value==null?'—':metric==='quantity'?number(value)+' adet':money(value);
export function salesChange(current,previous){
 if(current==null||previous==null)return null;
 if(previous===0)return current===0?0:null;
 return (current-previous)/Math.abs(previous)*100;
}
function change(period,metric,label,native,basis){
 const current=valueOf(period,metric,native,basis),previous=valueOf(period?.previous,metric,native,basis),percent=salesChange(current,previous);
 if(period?.partial)return i18nHtml('<small class="summary-comparison muted">Kısmi dönem · aktarılan kayıtlar</small>');
 if(percent===null)return i18nHtml`<small class="summary-comparison muted">${previous===0?i18nT('Önceki dönemde satış yok'):i18nT('Karşılaştırma verisi yok')}</small>`;
 return i18nHtml`<small class="summary-comparison ${percent>0?'up':percent<0?'down':'muted'}"><span>${percent>0?'↗':percent<0?'↘':'—'} ${percent>0?'+':''}${new Intl.NumberFormat(currentLocale(),{maximumFractionDigits:1}).format(percent)}%</span> ${esc(label)}</small>`;
}
export const summaryChart=financialChart;
function breakdown(period,item,grossOnly){
 if(!period?.available)return '';
 if(grossOnly){
  const adjusted=item.turnoverDisplayBasis==='after_cancellations';
  const amounts=[[adjusted&&!item.hideMarketplaceSalesCancellations?'İptal sonrası ciro':'Ciro',rawTurnoverValue(period)]];
  if(!item.hideMarketplaceSalesCancellations&&!adjusted&&Object.hasOwn(period,'salesTurnoverCents'))amounts.push(['İptaller Sonrası Ciro',period.salesTurnoverCents]);
  if(!item.hideMarketplaceSalesCancellations&&Object.hasOwn(period,'cancelCents'))amounts.push(['İptal',cancelledTurnoverValue(period)]);
  if(Object.hasOwn(period,'returnCents'))amounts.push(['İade',item.refundsVerified===false?null:period.returnCents]);
  return i18nHtml`<dl class="summary-breakdown">${amounts.map(([title,amount])=>i18nHtml`<div><dt>${i18nT(title)}</dt><dd>${amount===null?i18nT('Bekleniyor'):money(amount)}</dd></div>`).join('')}</dl>`;
 }
 const verified=item.refundsVerified!==false;
 const extra=item.panelBasis==='before_discounts'?[[i18nT('İndirim'),period.platformDiscountCents==null||period.sellerDiscountCents==null?null:period.platformDiscountCents+period.sellerDiscountCents]]:item.panelBasis==='buyer_payment'?[[i18nT('Pazaryeri katkısı'),period.platformDiscountCents]]:[];
 return i18nHtml`<dl class="summary-breakdown">${[...extra,...(Object.hasOwn(period,'cancelCents')?[[i18nT('İptal'),cancelledTurnoverValue(period)]]:[]),...(Object.hasOwn(period,'returnCents')?[[i18nT('İade'),verified?period.returnCents:null]]:[]),[i18nT(turnoverLabel(period)),turnoverValue(period)]].map(([label,value])=>i18nHtml`<div><dt>${label}</dt><dd>${value==null?i18nT('Bekleniyor'):money(value)}</dd></div>`).join('')}</dl>`;
}
export function summaryBody(item,metric,month,detailsOpen=false,amounts=true){
 if(!amounts)metric='quantity';
 const periods=item?.periods||{},known=item?.available,native=item?.displayBasis==='sales_before_returns';
 const grossOnly=Boolean(item?.turnoverDisplayBasis);
 const n11=item?.periodPolicy==='n11_inclusive';
 const metricBox=(key,label,comparison)=>i18nHtml`<div class="summary-metric ${key==='today'?'today':''}"><span>${label}</span><strong>${format(valueOf(periods[key],metric,native,grossOnly),metric)}</strong>${periods[key]?.from?i18nHtml`<small class="summary-range">${esc(day(periods[key].from))}${periods[key].to!==periods[key].from?' – '+esc(day(periods[key].to)):''}</small>`:''}${known?change(periods[key],metric,comparison,native,grossOnly):i18nHtml('<small class="summary-comparison muted">Veri bekleniyor</small>')}</div>`;
 const monthLabel=item?.selectedRange?day(item.selectedRange.from)+' – '+day(item.selectedRange.to):new Date(month+'-01T12:00:00+03:00').toLocaleDateString(currentLocale(),{month:'long',year:'numeric',timeZone:'Europe/Istanbul'});
 const periodNote=(n11?i18nT(' n11 panelindeki dönem kullanılır; başlangıç günü ve bugün dahildir.'):'')+(grossOnly?i18nT(' Tarihler Türkiye saatine göredir.'):i18nT(' Tarihler Türkiye saatine göredir; iptal ve iadeler sipariş gününde gösterilir.'));
 const detailRows=[['today',i18nT('Bugünkü satış')],['seven',n11?i18nT('Son 1 haftalık satış'):i18nT('Son 7 gün')],['thirty',n11?i18nT('Son 1 aylık satış'):i18nT('Son 30 gün')],['month',monthLabel]];
 const grossDays=(item?.days??[]).map(row=>({date:row.date,amountCents:rawTurnoverValue(row),quantity:Object.hasOwn(row,'orderedQuantity')?row.orderedQuantity:row.quantity}));
 const chartDays=grossOnly?grossDays:amounts?turnoverDays(item?.days):item?.days;
 const chartOptions=grossOnly?{...item,displayBasis:'turnover'}:amounts?{...item,displayBasis:'turnover'}:item;
 const details=i18nHtml`<details class="summary-details" data-summary-details ${detailsOpen?'open':''}><summary>${i18nT('Grafik ve hesaplama ayrıntıları')}</summary>${summaryChart(chartDays,metric,chartOptions)}${metric==='amount'?detailRows.filter(([key])=>periods[key]?.available).map(([key,label])=>i18nHtml`<section class="summary-detail-period"><h4>${esc(label)}</h4>${breakdown(periods[key],item,grossOnly)}</section>`).join(''):''}<p class="summary-source">${known?(amounts?(item?.hideMarketplaceSalesCancellations?'':i18nT(turnoverDescription(item)))+periodNote:periodNote):esc(i18nT(item?.message||'Günlük satış aktarımı bekleniyor.'))}</p></details>`;
 const switcher=amounts?i18nHtml`<div class="summary-switch" role="group" aria-label="Satış ölçüsü"><button type="button" data-sales-metric="quantity" aria-pressed="${metric==='quantity'}">Adet</button><button type="button" data-sales-metric="amount" aria-pressed="${metric==='amount'}">Tutar</button></div>`:'';
 return i18nHtml`<div class="summary-top"><h3>Satış özeti</h3>${switcher}</div><div class="summary-metrics">${metricBox('today',i18nT('Bugünkü satış'),i18nT('düne göre'))}${metricBox('seven',n11?i18nT('Son 1 haftalık satış'):i18nT('Son 7 gün'),n11?i18nT('önceki döneme göre'):i18nT('önceki 7 güne göre'))}${metricBox('thirty',n11?i18nT('Son 1 aylık satış'):i18nT('Son 30 gün'),n11?i18nT('önceki döneme göre'):i18nT('önceki 30 güne göre'))}</div>${periods.month?.available?i18nHtml`<div class="summary-month"><span>${esc(monthLabel)}</span><strong>${format(valueOf(periods.month,metric,native,grossOnly),metric)}</strong></div>`:''}${!known||(!grossOnly&&item?.refundsVerified===false)?i18nHtml`<p class="summary-availability">${!known?esc(i18nT(item?.message||'Günlük satış aktarımı bekleniyor.')):i18nT('İade verisi henüz aktarılmadı.')}</p>`:''}${details}${known?i18nHtml`<p class="summary-asof">Son aktarım: ${esc(new Date(item.asOf).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}))}</p>`:''}`;
}
function selectedMetric(state,channel){return state.salesSummarySelection?.company===state.company?state.salesSummarySelection?.values?.[channel]||'amount':'amount';}
function detailsExpanded(state,channel){return state.salesSummaryDetails?.company===state.company&&state.salesSummaryDetails?.values?.[channel]===true;}
function summaryItem(state,channel,grossOnly=false){
 let item=state.data.marketplaceSales?.channels.find(value=>value.channel===channel);
 const monthly=state.data.sales?.find(value=>value.channel===channel&&value.month===state.month);
 if(!item?.available&&monthly)item={...item,message:i18nT('Paylaşılan aylık özet mevcut; günlük aktarım bekleniyor.'),periods:{month:{...monthly,available:true,turnoverCents:rawTurnoverValue(monthly),quantity:null}}};
 return grossOnly||state.user?.role==='client'?{...item,hideMarketplaceSalesCancellations:state.data.hideMarketplaceSalesCancellations===true,turnoverDisplayBasis:state.data.turnoverDisplayBasis||'before_cancellations'}:item;
}
export function marketplaceSummary(state,channel,grossOnly=false){
 const amounts=salesTurnoverVisible(state.data);
 return i18nHtml`<div class="marketplace-summary sales-summary-refined" data-market-summary="${esc(channel)}"${grossOnly?' data-market-gross':''}>${summaryBody(summaryItem(state,channel,grossOnly),selectedMetric(state,channel),state.month,detailsExpanded(state,channel),amounts)}</div>`;
}
export function bindMarketplaceSales(root,state){
 const alignCards=bindSalesCardLayout(root);
 root.querySelectorAll('[data-market-summary]').forEach(card=>{
 card.addEventListener('toggle',event=>{
  if(!event.target.matches('[data-summary-details]'))return;
  if(state.salesSummaryDetails?.company!==state.company)state.salesSummaryDetails={company:state.company,values:{}};
  state.salesSummaryDetails.values[card.dataset.marketSummary]=event.target.open;
  alignCards();
 },true);
 card.addEventListener('click',event=>{
  const button=event.target.closest('[data-sales-metric]');if(!button)return;
  const metric=button.dataset.salesMetric,channel=card.dataset.marketSummary;
  if(!salesTurnoverVisible(state.data)||!['amount','quantity'].includes(metric))return;
  if(state.salesSummarySelection?.company!==state.company)state.salesSummarySelection={company:state.company,values:{}};
  state.salesSummarySelection.values[channel]=metric;
  const item=summaryItem(state,channel,card.hasAttribute('data-market-gross'));
  card.innerHTML=summaryBody(item,metric,state.month,detailsExpanded(state,channel));card.querySelector(`[data-sales-metric="${metric}"]`).focus({preventScroll:true});
  alignCards();
 });});
}
