import {currentLocale,t} from './i18n.js';

const moneyLabels=['Fatura tutarı','Tutar','Tutar (TL)','Kaynak tutarı','Alış fiyatı','Alış maliyeti','Satış tutarı'];
const integerLabels=['Adet','Stok','Pazaryeri stoğu','Mağaza stoğu','Toplam stok','Ürün adedi','Sipariş sayısı'];

export function exportColumnType(label){
 const clean=String(label).replace(/\s+/gu,' ').trim();
 if(moneyLabels.some(value=>value===clean||t(value)===clean))return 'money';
 if(integerLabels.some(value=>value===clean||t(value)===clean))return 'integer';
 return null;
}

/** Yalnız tutar/adet sütunları sayısaldır; kimlik bilgileri metin kalır. */
export function numericExportCell(text,type,locale=currentLocale()){
 if(!type)return {value:text};
 const [first,...notes]=String(text).split('\n');
 const parts=new Intl.NumberFormat(locale).formatToParts(12345.6);
 const group=parts.find(part=>part.type==='group')?.value,decimal=parts.find(part=>part.type==='decimal')?.value;
 let value=first.replace(/\p{Sc}|TRY|\s/gu,'').replaceAll('−','-');
 if(group)value=value.replaceAll(group,'');
 if(decimal)value=value.replaceAll(decimal,'.');
 if(!/^[+-]?\d+(?:\.\d+)?$/u.test(value))return {value:text};
 const number=Number(value);
 if(!Number.isFinite(number)||!Number.isSafeInteger(type==='integer'?number:Math.round(number*100)))return {value:text};
 return {value:number,...(notes.some(line=>line.trim())?{note:notes.join('\n').trim()}:{})};
}
