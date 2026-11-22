// Store money as integer kuruş; display separators never become part of the API value.
export const cleanMoneyEntry=value=>String(value??'').trim().replace(/^₺\s*|\s*₺$/g,'').replace(/\s+/g,'');
export function parseMoneyAmount(value,{optional=false,rate=false,negative=false}={}){
 const raw=cleanMoneyEntry(value);
 if(!raw){if(optional)return null;throw new Error('Tutarı girin.');}
 let normalized=raw;
 if(raw.includes(',')){
  if(!/^-?(?:\d+|\d{1,3}(?:\.\d{3})+),\d{0,2}$/.test(raw))throw new Error('Tutarı 1.234,56 veya 1234,56 biçiminde girin.');
  normalized=raw.replace(/\./g,'').replace(',','.');
 }else if(!rate&&/^-?\d{1,3}(?:\.\d{3})+$/.test(raw))normalized=raw.replace(/\./g,'');
 else if(!/^-?\d+(?:\.\d{1,2})?$/.test(raw))throw new Error('En fazla iki ondalık basamak kullanın.');
 const sign=normalized.startsWith('-')?-1:1;
 const [whole,fraction='']=normalized.replace(/^-/,'').split('.');
 const cents=sign*(Number(whole)*100+Number(fraction.padEnd(2,'0')));
 if(!Number.isSafeInteger(cents)||cents>(rate?10000:100000000000)||cents<(negative?-100000000000:0))throw new Error(rate?'Oran 0–100 arasında olmalı.':'Tutar izin verilen aralığın dışında.');
 return cents;
}
export function displayMoneyAmount(cents,{rate=false}={}){
 if(cents===null||cents===undefined)return '';
 if(!Number.isSafeInteger(cents))throw new Error('Geçersiz tutar.');
 const amount=Math.abs(cents),whole=String(Math.floor(amount/100)),fraction=String(amount%100).padStart(2,'0');
 return (cents<0?'-':'')+(rate?whole:whole.replace(/\B(?=(\d{3})+(?!\d))/g,'.'))+','+fraction;
}
export function formatMoneyEntry(value,{final=false,...options}={}){
 const raw=cleanMoneyEntry(value);
 if(!raw)return '';
 let cents;
 try{cents=parseMoneyAmount(raw,options);}catch{return String(value??'');}
 if(final)return displayMoneyAmount(cents,options);
 const fraction=raw.includes(',')?raw.slice(raw.indexOf(',')+1):/\.\d{1,2}$/.test(raw)?raw.split('.').at(-1):null;
 return displayMoneyAmount(cents,options).split(',')[0]+(fraction===null?'':','+fraction);
}
export function canonicalMoneyEntry(value,options={}){
 const cents=parseMoneyAmount(value,{optional:true,...options});
 return cents===null?'':displayMoneyAmount(cents).replace(/\./g,'').replace(',','.');
}
