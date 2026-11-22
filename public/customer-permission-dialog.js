import {loadingState,contentError} from './content-state.js';
import {t} from './i18n.js';
import {finishAccessCover} from './marketplace-access.js';
import {protectManagementDialog} from './management-access-flow.js';
import {openDialog} from './ui.js';

/** Allocate the settings frame once, including during a retry of the first read. */
export function loadPermissionDialog(read,current){
 let editor=null,finish;
 const loaded=new Promise(resolve=>{finish=resolve;});
 const lifecycle={
  attach(){},
  dispose(){editor?.dispose();finish(null);},
  canLeave:()=>editor?.canLeave()??true,
 };
 const dialog=openDialog(t('Kullanıcı Yetkileri'),'<section data-permission-loading></section>',{
  className:'customer-permissions-dialog',draftGuard:lifecycle,loading:true,
 });
 const placeholder=dialog.querySelector('[data-permission-loading]');
 const present=()=>{
  if(!dialog.open||!dialog.contains(placeholder))return false;
  if(current())return true;
  dialog.close();
  return false;
 };
 protectManagementDialog(dialog);
 finishAccessCover();
 const mount=(markup,guard)=>{
  if(!present())return null;
  const body=placeholder.parentNode;
  body.innerHTML=markup;
  editor=guard;editor.attach(dialog);
  requestAnimationFrame(()=>{if(dialog.open&&body.isConnected)document.dispatchEvent(new Event('omnigo-dialog-ready'));});
  return dialog;
 };
 async function load(){
  placeholder.innerHTML=loadingState('Mağaza ayarları yükleniyor…');
  try{
   const data=await read();
   finish(present()?{data,mount}:null);
  }catch(error){
   if(!present()){finish(null);return;}
   placeholder.innerHTML=contentError(error.message);
   const retry=document.createElement('button');retry.type='button';retry.className='btn';
   retry.textContent=t('Yeniden dene');retry.onclick=load;placeholder.append(retry);
  }
 }
 void load();
 return loaded;
}
