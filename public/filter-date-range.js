import {maskDateEntry} from './manual-date-value.js';
/** Local calendar dates only; API values remain YYYY-MM-DD. */
export function monthDateRange(today,{throughToday=false}={}){
 const [year,month]=today.split('-').map(Number);
 const lastDay=new Date(Date.UTC(year,month,0)).getUTCDate();
 return {from:today.slice(0,7)+'-01',to:throughToday?today:today.slice(0,7)+'-'+String(lastDay).padStart(2,'0')};
}

export function defaultWorkspaceRange(previous,company,view,today){
 if(previous?.company===company&&!previous.automatic)return previous;
 return {company,...monthDateRange(today,{throughToday:['overview','overviewSales','overviewTop','channels'].includes(view)}),automatic:true};
}

export function maskManualDate(value,options){
 return maskDateEntry(value,'date',options);
}
