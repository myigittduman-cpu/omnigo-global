import {parseDateEntry,displayDateEntry,maskDateEntry,dateEntryPlaceholder,dateEntryError} from './manual-date-value.js';

// Keep named ISO fields for existing form/API consumers; typing and validity belong to the visible input.
export function createManualDateField(source){
 const mode=source.type,initial=source.value,input=document.createElement('input');
 input.type='text';input.dataset.manualCalendar=mode;input.inputMode=mode==='datetime-local'?'text':'numeric';
 input.autocomplete='off';input.placeholder=dateEntryPlaceholder(mode,source.step);
 input.className=source.className;input.value=displayDateEntry(initial,mode);input.defaultValue=input.value;
 input.setAttribute('aria-label',source.getAttribute('aria-label')||source.labels?.[0]?.textContent.trim()||'Tarih');
 if(source.id){input.id=source.id+'--manual';for(const label of source.labels||[])if(label.htmlFor===source.id)label.htmlFor=input.id;}
 for(const name of ['aria-describedby','title'])if(source.hasAttribute(name))input.setAttribute(name,source.getAttribute(name));
 source.dataset.manualDateSource=mode;source.type='hidden';source.after(input);
 let lastValue=source.value,lastEmitted=source.value;
 const constraints=()=>({mode,min:source.min,max:source.max,required:source.required,step:source.step});
 const sync=()=>{
  input.disabled=source.disabled;input.readOnly=source.readOnly;input.required=source.required;
  if(source.value!==lastValue){lastValue=lastEmitted=source.value;input.value=displayDateEntry(source.value,mode);input.setCustomValidity('');}
 };
 const commit=()=>{
  sync();if(input.disabled)return true;
  const error=dateEntryError(input.value,constraints());input.setCustomValidity(error);
  source.value=error?'':parseDateEntry(input.value,mode);lastValue=source.value;
  return !error;
 };
 const emit=type=>source.dispatchEvent(new Event(type,{bubbles:true}));
 input.addEventListener('input',event=>{
  event.stopPropagation();if(event.isComposing)return;
  const raw=input.value,start=input.selectionStart??raw.length,end=input.selectionEnd??start;
  input.value=maskDateEntry(raw,mode,{editing:event.inputType?.startsWith('delete')||(event.inputType==='insertText'&&event.data?.length===1)});
  const position=offset=>{if(offset===raw.length)return input.value.length;const count=raw.slice(0,offset).replace(/\D/g,'').length;let index=0,seen=0;while(index<input.value.length&&seen<count){if(/\d/.test(input.value[index]))seen++;index++;}return index;};
  input.setSelectionRange(position(start),position(end));commit();emit('input');
 });
 const change=()=>{
  if(!commit()){input.reportValidity();return;}
  input.value=displayDateEntry(source.value,mode);
  if(source.value!==lastEmitted){lastEmitted=source.value;emit('change');}
 };
 input.addEventListener('change',event=>{event.stopPropagation();change();});
 input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!source.form){event.preventDefault();change();}});
 source.addEventListener('change',sync);
 // Existing validators address fields by name; forward their feedback to the visible control.
 source.setCustomValidity=message=>input.setCustomValidity(message);
 source.checkValidity=()=>input.checkValidity();source.reportValidity=()=>input.reportValidity();
 source.focus=options=>input.focus(options);source.scrollIntoView=options=>input.scrollIntoView(options);
 sync();
 return {input,source,sync,commit,reset(){source.value=initial;lastValue=lastEmitted=initial;input.value=displayDateEntry(initial,mode);input.setCustomValidity('');sync();}};
}
