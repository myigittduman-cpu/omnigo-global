import {rawTurnoverValue} from './sales-turnover.js';
import {html as i18nHtml,t} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,money} from './ui.js';

// Only SVG geometry changes with screen size. All points keep their source value.
export function salesTrendPlot(active,series,colors,{width,height,left,right=22,top=20,bottom=38,variant}){
 const values=active.flatMap(item=>item.days.map(rawTurnoverValue)).filter(Number.isFinite);
 const low=Math.min(0,...values),high=Math.max(100,...values);
 const scale=10**Math.floor(Math.log10((high-low)/4));
 const step=([1,2,5,10].find(n=>n*scale>=(high-low)/4)??10)*scale;
 const max=Math.ceil(high/step)*step,min=Math.floor(low/step)*step;
 const count=Math.max(...active.map(item=>item.days.length));
 const x=index=>left+index*(width-left-right)/Math.max(1,count-1);
 const y=value=>top+(max-value)*(height-top-bottom)/(max-min);
 const grid=Array.from({length:Math.round((max-min)/step)+1},(_,index)=>{
  const value=min+step*index;
  return i18nHtml`<line x1="${left}" x2="${width-right}" y1="${y(value)}" y2="${y(value)}" class="chart-grid"/><text x="${left-10}" y="${y(value)+4}" text-anchor="end">${esc(new Intl.NumberFormat(currentLocale(),{notation:'compact',maximumFractionDigits:1}).format(value/100))} ₺</text>`;
 }).join('');
 const paths=active.map(item=>{
  let pen=false;
  const paletteIndex=series.indexOf(item)%colors.length;
  const color=colors[paletteIndex];
  const path=item.days.map((day,index)=>{
   const value=rawTurnoverValue(day);
   if(!Number.isFinite(value)){pen=false;return '';}
   const command=(pen?'L':'M')+x(index)+' '+y(value);pen=true;return command;
  }).join(' ');
  const single=item.days.filter(day=>Number.isFinite(rawTurnoverValue(day))).length===1;
  const points=item.days.map((day,index)=>{
   const value=rawTurnoverValue(day);
   if(!Number.isFinite(value))return '';
   const label=[item.channel,day.date.split('-').reverse().join('.'),money(value),
    ...(day.coverageLimited?[t('Kısmi veri')]:day.partial?[t('Kısmi gün')]:[])].join(' · ');
   return i18nHtml`<circle class="${single?'chart-single-point':'chart-data-point'}" cx="${x(index)}" cy="${y(value)}" r="5" fill="${color}"><title>${esc(label)}</title></circle>`;
  }).join('');
  return i18nHtml`<g class="sales-trend-series color-${paletteIndex}"><path d="${path}" fill="none" stroke="${color}" stroke-width="2.5" vector-effect="non-scaling-stroke"/>${points}</g>`;
 }).join('');
 const slots=Math.min(count,variant==='mobile'?4:7);
 const indexes=Array.from({length:slots},(_,index)=>Math.round(index*(count-1)/Math.max(1,slots-1)));
 const labels=indexes.map(index=>i18nHtml`<text x="${x(index)}" y="${height-10}" text-anchor="${index===0?'start':index===count-1?'end':'middle'}">${esc(active[0].days[index]?.date.slice(5).split('-').reverse().join('.'))}</text>`).join('');
 return i18nHtml`<svg class="sales-trend sales-trend--${variant}" viewBox="0 0 ${width} ${height}" role="img" aria-label="Seçili pazaryerlerinin günlere göre cirosu">${grid}${paths}${labels}</svg>`;
}
