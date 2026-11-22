import {enhanceManualDateFilters} from './manual-date-filters.js';
import {createManualDateField} from './manual-date-field.js';

const selector='input[type="date"],input[type="month"],input[type="datetime-local"]';
const fields=new WeakMap(),bound=new WeakSet();
const within=(root,query)=>root?[...(root.matches?.(query)?[root]:[]),...root.querySelectorAll(query)]:[];
export function enhanceDateInputs(root){
 if(!root)return;
 // Preserve the existing manual filters and their masking behavior.
 enhanceManualDateFilters(root);
 for(const source of within(root,selector))fields.set(source,createManualDateField(source));
}
export function validateDateInputs(root,{report=true}={}){
 if(!root)return true;
 let invalid;
 for(const source of within(root,'input[data-manual-date-source]')){
  const field=fields.get(source);if(field&&!field.commit())invalid??=field.input;
 }
 for(const [start,end] of [['from','to'],['periodStart','periodEnd']]){
  const a=root.querySelector('input[name="'+start+'"]'),b=root.querySelector('input[name="'+end+'"]'),field=b&&fields.get(b);
  if(field&&!a?.disabled&&!b.disabled&&a?.value&&b.value&&a.value>b.value){field.input.setCustomValidity('Bitiş tarihi başlangıçtan önce olamaz.');invalid??=field.input;}
 }
 if(invalid&&report)invalid.reportValidity();return !invalid;
}
export function bindDateInputs(root){
 if(bound.has(root))return;bound.add(root);enhanceDateInputs(root);
 new MutationObserver(records=>{
  for(const record of records){
   if(record.type==='attributes')fields.get(record.target)?.sync();
   else for(const node of record.addedNodes)if(node.nodeType===1)enhanceDateInputs(node);
  }
 }).observe(root,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','readonly','required','value','min','max','step']});
 root.addEventListener('submit',event=>{
  if(!validateDateInputs(event.target)){event.preventDefault();event.stopImmediatePropagation();}
 },true);
 root.addEventListener('reset',event=>{for(const source of event.target.querySelectorAll('[data-manual-date-source]'))fields.get(source)?.reset();},true);
}
