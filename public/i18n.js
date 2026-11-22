// Presentation only: never translate record values, request parameters or status codes.
import {customerLanguage} from './customer-language.js';
import {catalog as baseCatalog} from './locales/catalog.js';
import {managementLabels} from './locales/management-clarity.js';
const catalog={...baseCatalog,...managementLabels};

export const languages=Object.freeze({tr:'Türkçe',en:'English',de:'Deutsch'});
const normalize=value=>Object.hasOwn(languages,value)?value:'tr';
let language='tr';
try{language=normalize(globalThis.localStorage?.getItem('omnigo.language'));}catch{}
export const currentLanguage=()=>language;
export const currentLocale=()=>({tr:'tr-TR',en:'en-GB',de:'de-DE'})[language];
export function setLanguage(value,{persist=true}={}){
 language=normalize(value);
 if(globalThis.document?.documentElement)document.documentElement.lang=language;
 if(persist)try{globalThis.localStorage?.setItem('omnigo.language',language);}catch{}
 globalThis.window?.dispatchEvent?.(new Event('omnigo-language-change'));
 return language;
}
export function t(source,context){
 if(typeof source!=='string'||language==='tr')return customerLanguage(source);
 const match=source.match(/^(\s*)([\s\S]*?)(\s*)$/);
 const value=catalog[typeof context==='string'&&context?`${context}\u0004${match[2]}`:match[2]]?.[language];
 return customerLanguage(value===undefined?source:match[1]+value+match[3]);
}
// Use only for code-owned display-label dictionaries, never records or form values.
export function labelMap(labels){
 const result={};
 for(const [key,source]of Object.entries(labels)){
  if(typeof source!=='string')throw new TypeError('Display labels must be strings');
  Object.defineProperty(result,key,{enumerable:true,get:()=>t(source)});
 }
 return Object.freeze(result);
}
export function labelList(labels){
 const result=[];
 for(const [index,source]of labels.entries())Object.defineProperty(result,index,{enumerable:true,get:()=>t(source)});
 return Object.freeze(result);
}
export function labelRows(rows){
 return rows.map(row=>{const copy=[...row];Object.defineProperty(copy,1,{enumerable:true,get:()=>t(row[1])});return copy;});
}
export function text(strings,...values){
 const source=strings.reduce((result,part,index)=>result+part+(index<values.length?`{${index}}`:''),'');
 return t(source).replace(/\{(\d+)\}/g,(match,index)=>index<values.length?String(values[index]):match);
}
const escapeAttribute=value=>String(value).replace(/[&"<>]/g,char=>({'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[char]));
export function translateMarkup(source){
 // The customer wording also applies in Turkish.
 // The caller supplies code-owned markup only. Interpolated record values are
 // inserted afterwards; their text is never searched in the translation catalog.
 let markup=source.replace(/<option\b([^>]*)>([^<]*)<\/option>/g,(whole,attributes,label)=>
  /\bvalue\s*=/.test(attributes)?whole:`<option${attributes} value="${escapeAttribute(label)}">${label}</option>`);
 return markup.replace(/<[^>]*>|[^<]+/g,part=>{
  if(part.startsWith('<'))return part.replace(/\b(title|aria-label|placeholder|alt|data-label)=(['"])(.*?)\2/g,
   (whole,name,quote,value)=>`${name}=${quote}${t(value).replaceAll(quote,quote==='"'?'&quot;':'&#39;').replaceAll('<','&lt;').replaceAll('>','&gt;')}${quote}`);
  return t(part);
 });
}
export function html(strings,...values){
 const source=typeof strings==='string'?strings:strings.reduce((result,part,index)=>result+part+(index<values.length?`{${index}}`:''),'');
 return translateMarkup(source).replace(/\{(\d+)\}/g,(match,index)=>index<values.length?String(values[index]):match);
}
setLanguage(language,{persist:false});
