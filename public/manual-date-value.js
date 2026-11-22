import {calendarValueAllowed} from './calendar-date.js';

export function parseDateEntry(value,mode='date'){
 const text=String(value??'').trim();if(!text)return '';
 let iso='';
 if(mode==='month'){
  const local=/^(\d{1,2})[./](\d{4})$/.exec(text),digits=/^(\d{2})(\d{4})$/.exec(text);
  const parts=local||digits;iso=parts?`${parts[2]}-${parts[1].padStart(2,'0')}`:text;
 }else{
  const local=/^(\d{1,2})[./](\d{1,2})[./](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/.exec(text);
  const digits=/^(\d{2})(\d{2})(\d{4})(?:(\d{2})(\d{2})(\d{2})?)?$/.exec(text);
  const parts=local||digits;
  iso=parts?`${parts[3]}-${parts[2].padStart(2,'0')}-${parts[1].padStart(2,'0')}`:text;
  if(parts?.[4]!==undefined)iso+=`T${parts[4].padStart(2,'0')}:${parts[5]}${parts[6]===undefined?'':':'+parts[6]}`;
 }
 return calendarValueAllowed(iso,{mode,required:true})?iso:'';
}
export function displayDateEntry(value,mode='date'){
 if(!value)return '';
 if(mode==='month')return `${value.slice(5,7)}.${value.slice(0,4)}`;
 const date=value.slice(0,10).split('-').reverse().join('.');
 return mode==='datetime-local'?`${date} ${value.slice(11)}`:date;
}
export function maskDateEntry(value,mode='date',{editing=false}={}){
 const text=String(value??'');
 const partial=mode==='month'?/^\d{0,2}\.\d{0,4}$/:mode==='datetime-local'?/^\d{0,2}\.\d{0,2}\.\d{0,4}(?: \d{0,2}(?::\d{0,2}){0,2})?$/:/^\d{0,2}\.\d{0,2}\.\d{0,4}$/;
 if(editing&&partial.test(text))return text;
 const iso=parseDateEntry(text,mode);
 if(iso)return displayDateEntry(iso,mode);
 if(!/^[\d\s./:]*$/.test(text))return text;
 const digits=text.replace(/\D/g,''),sizes=mode==='month'?[2,4]:mode==='datetime-local'?[2,2,4,2,2,2]:[2,2,4];
 if(digits.length>sizes.reduce((sum,size)=>sum+size,0))return text;
 const separators=mode==='month'?['.']:['.','.',' ',':',':'];let offset=0,result='';
 for(const [index,size] of sizes.entries()){const part=digits.slice(offset,offset+size);if(!part)break;result+=(index?separators[index-1]:'')+part;offset+=size;}
 return result;
}
export const dateEntryPlaceholder=(mode,step='')=>mode==='month'?'aa.yyyy':mode==='datetime-local'?'gg.aa.yyyy ss:dd'+(step!==''&&Number(step)<60?':ss':''):'gg.aa.yyyy';
export function dateEntryError(value,{mode='date',min='',max='',required=false,step=''}={}){
 if(!String(value).trim())return required?'Bu tarih alanını doldurun.':'';
 const iso=parseDateEntry(value,mode);
 if(!iso)return `Geçerli bir ${mode==='month'?'dönemi':'tarihi'} ${dateEntryPlaceholder(mode,step)} biçiminde yazın.`;
 return calendarValueAllowed(iso,{mode,min,max,required})?'':'Tarih izin verilen aralıkta olmalıdır.';
}
