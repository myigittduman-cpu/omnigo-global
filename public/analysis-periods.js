import {monthDateRange} from './filter-date-range.js';
import {currentLocale,html,t} from './i18n.js';
import {esc,now} from './ui.js';

const historyStart='2026-06-01';
const firstNamedMonth='2026-11';
const futureMonthCount=12;
const namedMonth=period=>/^\d{4}-(?:0[1-9]|1[0-2])$/.test(period??'');
const shiftMonth=(month,offset)=>{
 const [year,index]=month.split('-').map(Number);
 return new Date(Date.UTC(year,index-1+offset,1)).toISOString().slice(0,7);
};

export function analysisPeriodRange(period,today=now()){
 if(period==='history')return {from:historyStart,to:today};
 if(period==='current'||period===today.slice(0,7))return monthDateRange(today,{throughToday:true});
 return namedMonth(period)?monthDateRange(period+'-01'):null;
}

export function analysisMonthKeys(today=now()){
 const current=today.slice(0,7),last=shiftMonth(current,futureMonthCount),months=[];
 for(let month=firstNamedMonth<current?firstNamedMonth:current;month<=last;month=shiftMonth(month,1)){
  if(month!==current)months.push(month);
 }
 return months;
}

const showDate=(value,options)=>new Intl.DateTimeFormat(currentLocale(),{...options,timeZone:'Europe/Istanbul'})
 .format(new Date(value+'T12:00:00+03:00'));

export function analysisPeriodButtons(period,today=now()){
 const selected=period===today.slice(0,7)?'current':period;
 const historyLabel=showDate(historyStart,{dateStyle:'long'})+' – '+showDate(today,{dateStyle:'long'});
 return html`<button class="btn" type="button" data-analysis-period="history" aria-pressed="${selected==='history'}">${esc(historyLabel)}</button>
  <button class="btn" type="button" data-analysis-period="current" aria-pressed="${selected==='current'}">Bu Ay</button>
  ${analysisMonthKeys(today).map(month=>html`<button class="btn" type="button" data-analysis-period="${month}" aria-pressed="${selected===month}">${esc(showDate(month+'-01',{month:'long',year:'numeric'}))}</button>`).join('')}`;
}

export const emptyMonthlyTotals=Object.freeze({
 turnoverCents:0,salesTurnoverCents:0,cancelledTurnoverCents:0,returnCents:0,orders:0,soldUnits:0,
});

export function futureMonthReport({from,to,channel},company,channels){
 const series=channels.filter(market=>channel==='all'||channel===market.name)
  .map(market=>({channel:market.name,available:false,days:[],message:t('Aktarım bekleniyor.')}));
 return {company,from,to,channel,series,availableChannels:0,selectedChannels:series.length,
  totals:emptyMonthlyTotals,previousTotals:null,previousFrom:'',previousTo:'',products:[],payments:{items:[]},
  notes:[t('Bu ay için satış aktarımı bekleniyor.')],generatedAt:new Date().toISOString()};
}
