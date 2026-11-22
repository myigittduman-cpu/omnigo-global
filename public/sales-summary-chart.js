import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,money} from './ui.js';

const day=value=>new Date(value+'T12:00:00+03:00').toLocaleDateString(currentLocale(),{day:'numeric',month:'short',timeZone:'Europe/Istanbul'});
const count=value=>new Intl.NumberFormat(currentLocale(),{maximumFractionDigits:0}).format(value)+' adet';
export function summarySeries(metric,{displayBasis,panelBasis,refundsVerified=true,deductionsOnly=false,turnoverDisplayBasis}={}){
 if(metric==='quantity')return [{field:displayBasis==='sales_before_returns'?'salesQuantity':'quantity',label:i18nT('Satış adedi'),color:'#91b8e4',dash:''}];
 if(displayBasis==='turnover'&&!deductionsOnly)return [{field:'amountCents',label:i18nT(turnoverDisplayBasis?'Ciro':'İptaller sonrası ciro'),color:'#dfc38a',dash:''}];
 const series=[];
 if(displayBasis==='sales_before_returns')series.push({field:['before_discounts','buyer_payment'].includes(panelBasis)?'panelSalesCents':'salesCents',label:['before_discounts','buyer_payment'].includes(panelBasis)?i18nT('Panel satış tutarı'):i18nT('Satış (iade öncesi)'),color:'#dfc38a',dash:''});
 if(!deductionsOnly)series.push({field:'amountCents',label:refundsVerified?i18nT('Net satış'):i18nT('Aktarılan satış'),color:'#86ccb5',dash:''});
 series.push({field:'cancelCents',label:i18nT('İptal'),color:'#f19b95',dash:'5 3'});
 if(refundsVerified)series.push({field:'returnCents',label:i18nT('İade'),color:'#baacf5',dash:'2 3'});
 return series;
}
export function summaryChart(days=[],metric='amount',options={}){
 const series=summarySeries(metric,options).filter(s=>!['cancelCents','returnCents'].includes(s.field)||days.some(day=>Object.hasOwn(day,s.field))),values=series.flatMap(s=>days.map(d=>d[s.field])).filter(Number.isFinite);
 if(!days.length||!values.length)return i18nHtml('<div class="summary-chart-empty">Günlük grafik için veri bekleniyor.</div>');
 const max=Math.max(1,...values),min=Math.min(0,...values),bottom=112,point=(value,index)=>[8+index*404/Math.max(1,days.length-1),bottom-(value-min)/(max-min)*96];
 const paths=series.map(s=>{
  const segments=[];let current=[];
  days.forEach((d,index)=>{if(!Number.isFinite(d[s.field])){if(current.length)segments.push(current);current=[];}else current.push({xy:point(d[s.field],index),index});});if(current.length)segments.push(current);
  return i18nHtml`<g class="summary-series-${s.field}">${segments.map(segment=>i18nHtml`<path class="summary-line" stroke-dasharray="${s.dash}" d="${segment.map(({xy},i)=>(i?'L':'M')+xy.map(n=>n.toFixed(2)).join(',')).join(' ')}"/>`).join('')}${segments.flat().map(({xy,index})=>i18nHtml`<circle cx="${xy[0]}" cy="${xy[1]}" r="3"><title>${esc(day(days[index].date)+' · '+s.label+' · '+(metric==='quantity'?count(days[index][s.field]):money(days[index][s.field])))}</title></circle>`).join('')}</g>`;
 }).join('');
 const label=options.chartLabel||i18nT('Son 30 gün');
 return i18nHtml`<figure class="summary-chart ${metric==='quantity'?'quantity':''}"><svg viewBox="0 0 420 124" role="img" aria-label="${esc(i18nText`${label} günlük ${series.map(s=>s.label).join(', ')}`)} grafiği"><title>Aktarılmayan günler boş bırakılır.</title><path class="summary-grid" d="M8 16H412 M8 64H412 M8 112H412"/>${paths}</svg><figcaption><span>${esc(day(days[0].date))}</span><span>${esc(label)}</span><span>${esc(day(days.at(-1).date))}</span></figcaption><div class="summary-legend">${series.map(s=>i18nHtml`<span><i class="summary-series-${s.field}"></i>${esc(s.label)}</span>`).join('')}</div></figure>`;
}
