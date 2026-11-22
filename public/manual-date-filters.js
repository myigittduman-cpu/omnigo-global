import {maskManualDate} from './filter-date-range.js';
import {t} from './i18n.js';

export function parseManualDate(value){
 const text=String(value||'').trim();
 const local=/^(\d{1,2})[./](\d{1,2})[./](\d{4})$/.exec(text);
 const iso=/^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
 if(!local&&!iso)return '';
 const [year,month,day]=local?[+local[3],+local[2],+local[1]]:[+iso[1],+iso[2],+iso[3]];
 const date=new Date(Date.UTC(year,month-1,day));
 return year>=2000&&year<=2100&&date.getUTCFullYear()===year&&date.getUTCMonth()===month-1&&date.getUTCDate()===day?`${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`:'';
}
export const displayManualDate=value=>/^\d{4}-\d{2}-\d{2}$/.test(value||'')?value.split('-').reverse().join('.'):'';

// Only filter fields are enhanced. Stored values and existing export/API contracts stay ISO.
const forms='[data-order-filters],.report-filter,.sales-range-form,[data-delivery-search],.workspace-date-range';
export function syncManualDateFilters(root){
 root.querySelectorAll('input[data-manual-date]').forEach(input=>{
  const source=input.previousElementSibling;
  input.value=displayManualDate(source.value);input.dataset.dateError='';input.setCustomValidity('');
 });
}
export function enhanceManualDateFilters(root){
 const candidates=[...(root.matches?.('input[type=date]')?[root]:[]),...root.querySelectorAll('input[type=date]')];
 for(const source of candidates){
  if(!source.closest(forms))continue;
  const input=document.createElement('input');input.type='text';input.dataset.manualDate=source.name;
  input.inputMode='numeric';input.placeholder='gg.aa.yyyy';input.autocomplete='off';input.maxLength=10;
  input.required=source.required;input.disabled=source.disabled;input.className=source.className;
  input.value=displayManualDate(source.value);input.setAttribute('aria-label',source.getAttribute('aria-label')||source.labels?.[0]?.textContent.trim()||t('Tarih'));
  const min=source.min,max=source.max;
  source.type='hidden';source.required=false;source.after(input);
  const commit=()=>{
   const iso=parseManualDate(input.value),empty=!input.value.trim();
   let error=empty&&!input.required?'':!iso?t('Geçerli bir tarihi gg.aa.yyyy biçiminde yazın.'):min&&iso<min||max&&iso>max?t('Tarih izin verilen aralıkta olmalıdır.'):'';
   input.dataset.dateError=error;input.setCustomValidity(error);source.value=error?'':iso;
   source.dispatchEvent(new Event('input',{bubbles:true}));
   const form=source.form,from=form?.querySelector('[data-manual-date=from]'),to=form?.querySelector('[data-manual-date=to]');
   if(from&&to){const a=parseManualDate(from.value),b=parseManualDate(to.value);to.setCustomValidity(to.dataset.dateError||((a&&b&&a>b)?t('Bitiş tarihi başlangıçtan önce olamaz.'):''));}
  };
  input.addEventListener('input',event=>{
   const raw=input.value,caret=input.selectionStart??raw.length,digitsBefore=raw.slice(0,caret).replace(/\D/g,'').length;
   input.value=maskManualDate(raw,{editing:event.inputType?.startsWith('delete')||(event.inputType==='insertText'&&event.data?.length===1)});
   let position=0,count=0;while(position<input.value.length&&count<digitsBefore){if(/\d/.test(input.value[position]))count++;position++;}
   input.setSelectionRange(position,position);commit();
  });
  input.addEventListener('change',()=>{commit();if(input.checkValidity())input.value=displayManualDate(source.value);source.dispatchEvent(new Event('change',{bubbles:true}));});
 }
}
export function bindManualDateFilters(root){
 enhanceManualDateFilters(root);
 new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)enhanceManualDateFilters(node);}).observe(root,{subtree:true,childList:true});
}
