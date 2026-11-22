import {t as i18nT,labelList} from './i18n.js';
export const calendarMonths=labelList(['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık']);
export const calendarToday=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Istanbul',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
export const validCalendarMonth=value=>typeof value==='string'&&/^(19\d{2}|20\d{2}|2100)-(0[1-9]|1[0-2])$/.test(value);
export function validCalendarDate(value){
 if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value)||!validCalendarMonth(value.slice(0,7)))return false;
 const date=new Date(value+'T12:00:00Z');return Number.isFinite(date.getTime())&&date.toISOString().slice(0,10)===value;
}
export function shiftMonth(value,offset){
 if(!validCalendarMonth(value)||!Number.isInteger(offset))throw new Error(i18nT('Geçersiz dönem.'));
 const [year,month]=value.split('-').map(Number);return new Date(Date.UTC(year,month-1+offset,1)).toISOString().slice(0,7);
}
export function shiftCalendarDay(value,offset){
 if(!validCalendarDate(value))throw new Error(i18nT('Geçersiz tarih.'));
 const date=new Date(value+'T12:00:00Z');date.setUTCDate(date.getUTCDate()+offset);return date.toISOString().slice(0,10);
}
export function calendarDays(value){
 if(!validCalendarMonth(value))throw new Error(i18nT('Geçersiz dönem.'));
 const [year,month]=value.split('-').map(Number),first=new Date(Date.UTC(year,month-1,1)),offset=(first.getUTCDay()+6)%7;
 return Array.from({length:42},(_,index)=>{const day=new Date(Date.UTC(year,month-1,1-offset+index));return {date:day.toISOString().slice(0,10),day:day.getUTCDate(),current:day.getUTCMonth()===month-1};});
}
export function calendarValueAllowed(value,{mode='date',min='',max='',required=false}={}){
 if(!value)return !required;
 const valid=mode==='month'?validCalendarMonth(value):mode==='date'?validCalendarDate(value):mode==='datetime-local'&&validCalendarDate(value.slice(0,10))&&/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value);
 const comparable=text=>mode==='datetime-local'&&text.length===16?text+':00':text;
 return valid&&(!min||comparable(value)>=comparable(min))&&(!max||comparable(value)<=comparable(max));
}
