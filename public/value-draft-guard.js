import {confirmPendingChanges} from './pending-changes.js';

/** Draft lifecycle for settings that are not native forms (for example menu order). */
export function createValueDraftGuard({label,read,write,restore,current=()=>true}){
 let baseline=JSON.stringify(read()),dialog,pending=null,busy=false;
 const changed=()=>JSON.stringify(read())!==baseline;
 const unload=event=>{if(dialog?.open&&(busy||changed())){event.preventDefault();event.returnValue='';}};
 const guard={
  attach(value){dialog=value;window.addEventListener('beforeunload',unload);},
  dispose(){window.removeEventListener('beforeunload',unload);},
  isBusy:()=>busy,
  async save(){
   if(busy||!current())return false;busy=true;
   const value=structuredClone(read());
   try{if(await write(value)===false)return false;baseline=JSON.stringify(value);return true;}
   finally{busy=false;}
  },
  async canLeave(){
   if(busy)return false;if(!dialog?.open||!changed())return true;if(pending)return pending;
   pending=confirmPendingChanges({changes:[label],current:()=>dialog.open&&current(),save:()=>guard.save(),discard:()=>{restore(JSON.parse(baseline));return true;}}).finally(()=>pending=null);
   return pending;
  }
 };
 return guard;
}
