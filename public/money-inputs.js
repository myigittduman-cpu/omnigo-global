import {formatMoneyEntry,parseMoneyAmount} from './money-value.js';

const selector='input[data-line-amount],input[data-line-rate],input[name$="Cents"],input[name="invoiceAmount"]';
const fields=new WeakSet(),roots=new WeakSet();
const options=input=>({optional:!input.required,negative:input.hasAttribute('data-line-amount'),rate:input.hasAttribute('data-line-rate')});
function format(input,final=false,event){
 const raw=input.value,start=input.selectionStart??raw.length,end=input.selectionEnd??start;
 // During an edit existing dots are grouping marks, not a newly typed decimal.
 const editing=event?.inputType?.startsWith('delete')||(event?.inputType==='insertText'&&event.data?.length===1);
 let entry=raw;
 if(editing){if(event.data==='.'&&start>0)entry=entry.slice(0,start-1)+','+entry.slice(start);entry=entry.replace(/\./g,'');}
 const formatted=formatMoneyEntry(entry,{...options(input),final});
 const value=input.hasAttribute('data-try-prefix')&&/^[-\d]/.test(formatted)?'₺'+formatted:formatted;
 if(raw===value)return;
 // Count meaningful characters, so grouping dots do not move the cursor to the end.
 const decimalDot=!raw.includes(',')&&value.includes(',')&&/\.\d{1,2}(?:\s*₺)?$/.test(raw);
 const tokens=text=>text.replace(decimalDot?/\.(?=\d{0,2}(?:\s*₺)?$)/:/$^/,',').replace(/[^\d,-]/g,'').length;
 const position=offset=>{if(offset===raw.length)return value.length;const count=tokens(raw.slice(0,offset));let seen=0,index=0;while(index<value.length&&seen<count){if(/[\d,-]/.test(value[index]))seen++;index++;}return index;};
 input.value=value;
 if(document.activeElement===input)input.setSelectionRange(position(start),position(end));
}
function validate(input){
 input.setCustomValidity('');if(input.disabled)return true;
 try{parseMoneyAmount(input.value,options(input));return true;}
 catch(error){input.setCustomValidity(error.message);return false;}
}
export function enhanceMoneyInputs(root){
 const inputs=[...(root.matches?.(selector)?[root]:[]),...root.querySelectorAll(selector)];
 for(const input of inputs){
  if(fields.has(input))continue;fields.add(input);input.type='text';input.inputMode='decimal';input.autocomplete='off';
  format(input,true);
  input.addEventListener('input',event=>{if(event.isComposing)return;format(input,false,event);validate(input);});
  input.addEventListener('change',()=>{format(input,true);validate(input);});
  input.addEventListener('blur',()=>{format(input,true);validate(input);});
 }
}
export function bindMoneyInputs(root){
 if(roots.has(root))return;roots.add(root);enhanceMoneyInputs(root);
 new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node.nodeType===1)enhanceMoneyInputs(node);}).observe(root,{childList:true,subtree:true});
 root.addEventListener('submit',event=>{
  if(event.target.noValidate)return;
  for(const input of event.target.querySelectorAll(selector))if(!validate(input)){event.preventDefault();event.stopImmediatePropagation();input.reportValidity();return;}
 },true);
 root.addEventListener('reset',event=>queueMicrotask(()=>{for(const input of event.target.querySelectorAll(selector)){format(input,true);input.setCustomValidity('');}}));
}
