import {cancelledTurnoverValue,rawTurnoverValue} from './sales-turnover.js';
import {t as i18nT,html as i18nHtml} from './i18n.js';
import {salesTrendPlot} from './sales-trend-plot.js';
export function deductionDays(active){
 return (active[0]?.days||[]).map((day,index)=>{
  const row={date:day.date};
  for(const field of ['cancelCents','returnCents']){
   if(!active.some(s=>s.days.some(d=>Object.hasOwn(d,field))))continue;
   const values=active.map(s=>s.days[index]?.date===day.date?(field==='cancelCents'?cancelledTurnoverValue(s.days[index]):s.days[index][field]):null);
   row[field]=values.every(Number.isFinite)?values.reduce((a,b)=>a+b,0):null;
  }
  return row;
 });
}
export const chartColors=['#cdb780','#78c8c6','#b19be1','#f0ac83','#97bd88','#83b5ef'];
export function salesChart(series,hidden=[]){
 const active=series.filter(s=>s.available&&!hidden.includes(s.channel));
 const hasValues=active.some(s=>s.days.some(day=>rawTurnoverValue(day)!==null));
 if(!hasValues)return i18nHtml`<p class="report-empty">${active.length||!series.some(s=>s.available)?i18nT('Grafik için aktarılmış günlük veri bulunmuyor.'):i18nT('Grafiği göstermek için bir pazaryeri seçin.')}</p>`;
 return salesTrendPlot(active,series,chartColors,{width:1000,height:290,left:76,variant:'desktop'})+
  salesTrendPlot(active,series,chartColors,{width:400,height:240,left:64,variant:'mobile'});
}
