import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {confirmPendingChanges} from './pending-changes.js';
import {beginSaveFeedback,finishSaveFeedback} from './save-feedback.js';

const controllers=new WeakMap();
const writableForms='form.form-grid,form.delivery-correction-form';
const fields=form=>[...form.elements].filter(field=>field.name&&!field.readOnly&&!['submit','button','reset','hidden'].includes(field.type));
export function draftValues(form){
 return fields(form).map(field=>[field.name,field.type==='file'?[...field.files].map(file=>[file.name,file.size,file.lastModified]):['checkbox','radio'].includes(field.type)?field.checked:field.value]);
}
const snapshot=form=>JSON.stringify(draftValues(form));
const changedFields=(form,before)=>{
 const prior=JSON.parse(before),now=draftValues(form);
 return fields(form).filter((field,index)=>JSON.stringify(now[index])!==JSON.stringify(prior[index])).map(field=>{
  const label=field.labels?.[0];return label?.querySelector('span')?.textContent?.trim()||[...(label?.childNodes||[])].filter(node=>node.nodeType===3).map(node=>node.textContent.trim()).join(' ')||i18nT('Form alanı');
 });
};

/** Observe only editable dialogs. Search/filter forms never become pending writes. */
export function watchDialogDrafts(dialog,title,{guard}={}){
 controllers.get(dialog)?.dispose();
 if(guard){
  guard.attach(dialog);controllers.set(dialog,guard);
  dialog.addEventListener('close',()=>{if(controllers.get(dialog)===guard){guard.dispose();controllers.delete(dialog);}},{once:true});
  return guard;
 }
 const forms=new Map(),wrapped=new WeakSet();let pending=null,saving=0,discarded=false;
 const prepare=form=>{
  if(wrapped.has(form)||form.onsubmit?.constructor.name!=='AsyncFunction')return;
  wrapped.add(form);const submit=form.onsubmit;
  form.onsubmit=async event=>{
   saving++;const feedback=beginSaveFeedback();let saved=false;
   try{
    const alert=form.querySelector('.form-error,[role=alert]');if(alert)alert.textContent='';
    const result=await submit.call(form,event);
    if(result===false||alert?.textContent?.trim())return false;
    if(dialog.contains(form))forms.set(form,snapshot(form));saved=true;return result;
   }finally{saving--;finishSaveFeedback(feedback,saved);}
  };
 };
 const collect=()=>{
  for(const form of [...forms.keys()])if(!dialog.contains(form))forms.delete(form);
  for(const form of dialog.querySelectorAll(writableForms)){if(!forms.has(form))forms.set(form,snapshot(form));prepare(form);}
 };
 const dirty=()=>{collect();return [...forms].filter(([form,before])=>snapshot(form)!==before);};
 const unload=event=>{if(dialog.open&&!discarded&&(saving||dirty().length)){event.preventDefault();event.returnValue='';}};
 globalThis.window?.addEventListener('beforeunload',unload);
 const label=(form,before)=>`${title} · ${[...new Set(changedFields(form,before))].slice(0,5).join(', ')}`;
 const controller={
  acceptDefaults(form,names){
   collect();const before=forms.get(form);if(!before)return;
   const prior=JSON.parse(before),now=draftValues(form);
   for(let i=0;i<now.length;i++)if(names.includes(now[i][0]))prior[i]=now[i];
   forms.set(form,JSON.stringify(prior));
  },
  dispose(){globalThis.window?.removeEventListener('beforeunload',unload);observer.disconnect();dialog.removeEventListener('input',collect);dialog.removeEventListener('change',collect);controllers.delete(dialog);},
  suspend(){globalThis.window?.removeEventListener('beforeunload',unload);observer.disconnect();dialog.removeEventListener('input',collect);dialog.removeEventListener('change',collect);},
  resume(){globalThis.window?.addEventListener('beforeunload',unload);observer.observe(dialog,{childList:true,subtree:true});dialog.addEventListener('input',collect);dialog.addEventListener('change',collect);},
  async canLeave({source='',target='',withinDialog=false}={}){
   if(!dialog.open||discarded)return true;
   if(saving)return false;
   const entries=dirty();if(!entries.length)return true;
   if(pending)return pending;
   pending=confirmPendingChanges({source,target,changes:entries.map(([form,before])=>label(form,before)),current:()=>dialog.open,
    save:async()=>{
     const changed=dirty();
     // Existing multi-form screens may rebuild after a save. Never silently lose a sibling form.
     if(changed.length>1)throw new Error(i18nT('Birden fazla formda değişiklik var. Vazgeç ile pencereye dönüp her formu kendi Kaydet düğmesinden kaydedin. Müşteri değiştirilmedi.'));
     for(const [form] of changed){
      if(form.querySelector('button[type=submit]:disabled'))throw new Error(i18nT('Devam eden kayıt işleminin bitmesini bekleyin.'));
      if(!form.checkValidity())throw new Error(i18nT('Eksik veya geçersiz alanlar var. Vazgeç ile forma dönüp alanları tamamlayın.'));
      if(typeof form.onsubmit!=='function'||form.onsubmit.constructor.name!=='AsyncFunction')throw new Error(i18nT('Vazgeç ile forma dönüp bu işlemi kendi Kaydet düğmesinden tamamlayın.'));
      saving++;
      try{
       const result=await form.onsubmit(new Event('submit',{cancelable:true}));
       const error=form.querySelector('.form-error,[role=alert]')?.textContent?.trim();
       if(result===false||error)throw new Error(error||i18nT('Kayıt tamamlanamadı. Bilgileriniz korunuyor.'));
       if(dialog.contains(form))forms.set(form,snapshot(form));
      }finally{saving--;}
     }
     return true;
    },
    discard:()=>{
     if(withinDialog){
      for(const [form,before] of dirty()){
       const prior=JSON.parse(before);
       fields(form).forEach((field,index)=>{if(!prior[index])return;if(['checkbox','radio'].includes(field.type))field.checked=prior[index][1];else field.value=field.type==='file'?'':prior[index][1];});
       forms.set(form,snapshot(form));
      }
     }else discarded=true;
     return true;
    }
   }).finally(()=>{pending=null;});
   return pending;
  }
 };
 const observer=new MutationObserver(collect);observer.observe(dialog,{childList:true,subtree:true});
 collect();dialog.addEventListener('input',collect);dialog.addEventListener('change',collect);
 // Wait for initial renderer defaults and payment fields before establishing the baseline.
 queueMicrotask(()=>{if(dialog.open&&!saving)for(const form of dialog.querySelectorAll(writableForms)){forms.set(form,snapshot(form));prepare(form);}});
 controllers.set(dialog,controller);return controller;
}

export async function canLeaveDialogDrafts(options={}){
 if(!options.withinDialog&&document.querySelector?.('form[data-inline-edit]')?.canLeaveInlineEdit?.()===false)return false;
 const dialog=document.getElementById('dialog');return await controllers.get(dialog)?.canLeave(options)!==false;
}

/** Keep a parent controller and its baselines while a child uses the same surface. */
export function suspendDialogDrafts(dialog){
 const parent=controllers.get(dialog);parent?.suspend?.();controllers.delete(dialog);
 let finished=false;
 return {
  restore(){if(finished)return;finished=true;controllers.get(dialog)?.dispose();if(parent){controllers.set(dialog,parent);parent.resume?.();}},
  dispose(){if(finished)return;finished=true;const child=controllers.get(dialog);parent?.dispose();if(child&&child!==parent)controllers.set(dialog,child);}
 };
}

/** Accept only asynchronously loaded defaults, retaining unrelated user edits. */
export function acceptDialogFieldDefaults(form,names){
 controllers.get(form.closest('dialog'))?.acceptDefaults?.(form,names);
}
