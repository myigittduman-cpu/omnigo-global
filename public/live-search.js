import {preserveTablePosition} from './table-page-scroll.js';
// Opt in only read-only filter forms. Never submit account or mutation forms.
export function bindLiveSearch(root,{formSelector,fieldName,current,isBusy=()=>false,delay=300}){
 let timer,pending=false,composing=false,disposed=false;
 const field=()=>root.querySelector(formSelector)?.elements.namedItem(fieldName);
 const matches=input=>input?.name===fieldName&&input.form?.matches(formSelector);
 const configure=()=>{
  const input=field();if(!input)return;
  input.autocomplete='off';input.setAttribute('results','0');input.removeAttribute('autosave');input.removeAttribute('list');
  input.form.autocomplete='off';
 };
 const cancel=()=>{clearTimeout(timer);timer=undefined;pending=false;};
 const run=()=>{
  timer=undefined;
  if(disposed||!current()){cancel();return;}
  if(!pending||composing)return;
  if(isBusy()){timer=setTimeout(run,100);return;}
  const input=field();if(!input){cancel();return;}
  pending=false;input.form.requestSubmit();
 };
 const schedule=()=>{clearTimeout(timer);pending=true;if(!composing)timer=setTimeout(run,delay);};
 const input=event=>{if(matches(event.target))schedule();};
 const compositionStart=event=>{if(matches(event.target)){composing=true;clearTimeout(timer);}};
 const compositionEnd=event=>{if(matches(event.target)){composing=false;schedule();}};
 const submit=event=>{if(event.target.matches(formSelector)&&!isBusy())cancel();};
 root.addEventListener('input',input);
 root.addEventListener('compositionstart',compositionStart);
 root.addEventListener('compositionend',compositionEnd);
 root.addEventListener('submit',submit,true);
 configure();
 return {
  paint(render){
   const previous=field(),focused=previous&&root.ownerDocument.activeElement===previous;
   const preserve=previous&&(focused||pending),value=preserve?previous.value:null;
   const selection=focused?[previous.selectionStart,previous.selectionEnd,previous.selectionDirection]:null;
   const restore=preserveTablePosition(root);render();configure();restore();
   const next=field();if(!next)return;
   // Search stays editable while a read is in flight; only the latest draft is queued.
   next.disabled=false;
   if(preserve)next.value=value;
   if(focused){next.focus({preventScroll:true});if(selection[0]!==null)next.setSelectionRange(...selection);}
  },
  dispose(){disposed=true;cancel();root.removeEventListener('input',input);root.removeEventListener('compositionstart',compositionStart);root.removeEventListener('compositionend',compositionEnd);root.removeEventListener('submit',submit,true);}
 };
}
