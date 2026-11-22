import {currentLocale} from './i18n.js';
const firstMonth='2026-06';
export function shipmentCurrentMonth(date=new Date()){
 const parts=new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Istanbul',year:'numeric',month:'2-digit'}).formatToParts(date);
 return `${parts.find(part=>part.type==='year').value}-${parts.find(part=>part.type==='month').value}`;
}
export function shipmentMonthLabel(value,{year=true}={}){
 if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(value||''))return '';
 const [yyyy,mm]=value.split('-').map(Number);
 return new Intl.DateTimeFormat(currentLocale(),{timeZone:'UTC',month:'long',...(year?{year:'numeric'}:{})}).format(new Date(Date.UTC(yyyy,mm-1,1)));
}
export function availableShipmentMonths(date=new Date()){
 const current=shipmentCurrentMonth(date),last=current>'2026-12'?current:'2026-12',months=[];
 for(let year=2026,month=6;;){
  const value=`${year}-${String(month).padStart(2,'0')}`;if(value>last)break;
  months.push({value,get label(){return shipmentMonthLabel(value);}});if(++month>12){month=1;year++;}
 }
 return months;
}
export function shipmentMonthGroups(date=new Date()){
 const available=availableShipmentMonths(date),allowed=new Set(available.map(item=>item.value));
 return [...new Set(available.map(item=>item.value.slice(0,4)))].map(year=>({year,months:Array.from({length:12},(_,index)=>{
  const value=year+'-'+String(index+1).padStart(2,'0');
  return {value,label:shipmentMonthLabel(value,{year:false}),disabled:!allowed.has(value)};
 })}));
}
export function defaultShipmentMonth(date=new Date()){
 const current=shipmentCurrentMonth(date);return current<firstMonth?firstMonth:current;
}
