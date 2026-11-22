import {t} from './i18n.js';
import {confirmPendingChanges} from './pending-changes.js';
import {beginSaveFeedback,finishSaveFeedback} from './save-feedback.js';

const controls=form=>[...form.elements].filter(field=>field.name&&!['submit','button','reset','hidden'].includes(field.type));
const snapshot=form=>JSON.stringify(controls(form).map(field=>[field.name,['checkbox','radio'].includes(field.type)?field.checked:field.value]));

/** Personal settings keep their draft until a confirmed save or an explicit discard. */
export function createSettingsDraftGuard(){
 const entries=new Map();let dialog,pending=null,saving=0;
 const dirty=()=>[...entries.values()].filter(entry=>entry.form.isConnected&&entry.changed());
 const unload=event=>{if(dialog?.open&&(saving||dirty().length)){event.preventDefault();event.returnValue='';}};
 return {
  attach(target){dialog=target;window.addEventListener('beforeunload',unload);},
  suspend(){window.removeEventListener('beforeunload',unload);},
  resume(){window.addEventListener('beforeunload',unload);},
  dispose(){window.removeEventListener('beforeunload',unload);entries.clear();},
  async runOperation(work){saving++;try{return await work();}finally{saving--;}},
  hasOtherChanges(form){return dirty().some(entry=>entry.form!==form);},
  track(form,{label,save,manageButton=true}){
   for(const [old] of entries)if(!old.isConnected)entries.delete(old);
   const existing=entries.get(form);if(existing)return existing;
   let baseline=snapshot(form),busy=false;
   const button=form.querySelector('[type=submit]');
   const entry={form,label,changed:()=>snapshot(form)!==baseline,
    refresh(){if(manageButton&&button)button.disabled=busy||!entry.changed();},
    reset(){baseline=snapshot(form);entry.refresh();},
    async save(){
     if(busy)return false;if(!entry.changed())return true;
     if(!form.reportValidity())return false;
     if(!save)throw new Error(t('Şifre işlemini önce ilgili formdaki onay adımlarıyla tamamlayın.'));
     const values=Object.fromEntries(new FormData(form));
     busy=true;saving++;const fields=controls(form).map(field=>[field,field.disabled]);
     fields.forEach(([field])=>field.disabled=true);entry.refresh();
     const feedback=beginSaveFeedback();let saved=false;
     try{if(await save(values)===false)return false;entry.reset();saved=true;return true;}
     finally{busy=false;saving--;fields.forEach(([field,disabled])=>field.disabled=disabled);entry.refresh();finishSaveFeedback(feedback,saved);}
    }
   };
   // Capture submitted values before disabling inputs: FormData ignores disabled controls.
   if(save)form.onsubmit=async event=>{event.preventDefault();return entry.save();};
   form.addEventListener('input',entry.refresh);form.addEventListener('change',entry.refresh);
   entries.set(form,entry);entry.refresh();return entry;
  },
  async canLeave(){
   if(!dialog?.open)return true;if(saving)return false;
   const changes=dirty();if(!changes.length)return true;if(pending)return pending;
   pending=confirmPendingChanges({changes:changes.map(entry=>entry.label),current:()=>dialog.open,
    save:async()=>{for(const entry of dirty())if(await entry.save()===false)return false;return true;},
    discard:()=>{for(const entry of dirty())entry.reset();return true;}
   }).finally(()=>pending=null);
   return pending;
  }
 }
}
