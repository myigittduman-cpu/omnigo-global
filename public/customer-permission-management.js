import {createSettingsDraftGuard} from './settings-drafts.js';
import {ensureMarketplaceAccess,finishAccessCover} from './marketplace-access.js';
import {loadingState,contentError} from './content-state.js';
import {t} from './i18n.js';
import {toast} from './ui.js';

const loaders={
 accounts:async()=>(await import('./permission-account-directory.js')).mountPermissionAccountDirectory,
 defaults:async()=>(await import('./application-role-defaults.js')).mountPermissionRoleDefaults,
};

/** Manage independent forms in the persistent store settings shell. */
export function permissionManagementPanels(state,{companyId,dialog,current,blocked,canLeaveStore,reloadStore,onUpdated,onAccountUpdated}){
 let session=null,refreshRequired=false,transition=false;
 function unmount(){
  const old=session;
  session=null;
  if(!old)return;
  old.dispose?.();
  old.drafts.dispose();
  old.panel.replaceChildren();
 }
 async function canSelect(next){
  if(!current()||blocked()||transition)return false;
  transition=true;
  try{
   if(Object.hasOwn(loaders,next)&&!await ensureMarketplaceAccess(state,{id:0,name:t('Kullanıcı Yetkileri')}))return false;
   if(!current()||blocked())return false;
   if(session){
    if(!await session.drafts.canLeave()||!current())return false;
    if(refreshRequired&&!Object.hasOwn(loaders,next)){
     const leaving=session.panel;
     leaving.inert=true;
     try{
      if(!await reloadStore()||!current())return false;
      refreshRequired=false;
     }finally{leaving.inert=false;}
    }
   }else if(Object.hasOwn(loaders,next)&&(!await canLeaveStore()||!current()))return false;
   return true;
  }catch(error){if(current())toast(error.message);return false;}
  finally{transition=false;finishAccessCover();}
 }
 function onSelect(key,panel,onReady){
  unmount();
  if(!Object.hasOwn(loaders,key))return;
  const drafts=createSettingsDraftGuard();
  drafts.attach(dialog);
  const view={panel,drafts,dispose:null};
  session=view;
  const present=()=>session===view&&current()&&!panel.hidden;
  async function load(){
   panel.innerHTML=loadingState(key==='accounts'?'Kullanıcılar yükleniyor…':'Rol varsayılanları yükleniyor…');
   try{
    const mount=await loaders[key]();
    if(!present())return;
    const dispose=await mount(panel,state,{companyId,drafts,current:present,onReady:()=>{if(present())onReady();},onAccountUpdated,onUpdated:()=>{
     if(present()){refreshRequired=true;onUpdated(key);}
    }});
    if(present())view.dispose=dispose;
    else dispose?.();
   }catch(error){
    if(!present())return;
    panel.innerHTML=contentError(error.message);
    const retry=document.createElement('button');retry.type='button';retry.className='btn';
    retry.textContent=t('Yeniden dene');retry.onclick=load;panel.append(retry);
   }
  }
  void load();
 }
 return {
  canSelect,onSelect,unmount,
  canLeave:()=>transition?false:session?session.drafts.canLeave():canLeaveStore(),
  switching:()=>transition,
 };
}
