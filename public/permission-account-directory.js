import {bindAccountDirectory} from './account-directory.js';
import {accountDirectoryShell} from './account-directory-view.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {loadingState} from './content-state.js';
import {addDialogBack} from './dialog-return.js';
import {html,t} from './i18n.js';
import {finishAccessCover} from './marketplace-access.js';
import {createSettingsDraftGuard} from './settings-drafts.js';
import {esc,openDialog,toast} from './ui.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';

export function mountPermissionAccountDirectory(panel,state,{companyId,current,drafts,onUpdated,onAccountUpdated,onReady}){
 const company=state.companies?.find(item=>Number(item.id)===Number(companyId));
 if(state.user?.role!=='admin'||!company||!current())return;
 panel.innerHTML=accountDirectoryShell()+html`<section data-directory-detail hidden></section>`;
 const root=panel.querySelector('[data-account-directory]'),detail=panel.querySelector('[data-directory-detail]');
 const valid=()=>panel.isConnected&&state.user?.role==='admin'&&current();
 let opening=false;
 const openChild=async(open,button,title)=>{
  if(opening||!detail.hidden||!valid())return;
  opening=true;
  try{
   if(!await drafts.canLeave()||!valid())return;
   const editId=button?.getAttribute('data-directory-edit');
   const restoreFocus=()=>root.querySelector(editId?`[data-directory-edit="${CSS.escape(editId)}"]`:'[data-directory-create]');
   const scroller=['auto','scroll'].includes(getComputedStyle(panel).overflowY)?panel:panel.closest('.dialog-body');
   const top=scroller?.scrollTop||0;
   const headingOffset=scroller&&scroller!==panel?Math.max(0,top+panel.getBoundingClientRect().top-scroller.getBoundingClientRect().top):0;
   const tablePosition=root.querySelector('.account-directory-scroll')?.scrollLeft||0;
   detail.innerHTML=html`<header class="account-directory-heading"><h3 tabindex="-1">${esc(title)}</h3><button class="btn" type="button" data-directory-return>Kullanıcı listesine dön</button></header><div data-directory-detail-body><div data-directory-opening>${loadingState('Hesap bilgileri hazırlanıyor…')}</div></div>`;
   const host=detail.querySelector('[data-directory-detail-body]');
   const active=()=>valid()&&host.isConnected&&!detail.hidden;
   function back(){
    if(!active())return;
    detail.hidden=true;detail.replaceChildren();root.hidden=false;
    if(scroller)scroller.scrollTop=top;
    const table=root.querySelector('.account-directory-scroll');
    if(table)table.scrollLeft=tablePosition;
    (restoreFocus()||root.querySelector('[data-directory-create]'))?.focus({preventScroll:true});
   }
   detail.querySelector('[data-directory-return]').onclick=async()=>{if(await drafts.canLeave())back();};
   root.hidden=true;detail.hidden=false;
   if(scroller)scroller.scrollTop=Math.min(top,headingOffset);
   detail.querySelector('h3').focus({preventScroll:true});
   try{
    await open({host,drafts,current:active,onDone:back});
    if(active()&&host.querySelector('[data-directory-opening]'))back();
   }catch(error){if(active()){back();toast(error.message);}}
  }finally{opening=false;finishAccessCover();}
 };
 return bindAccountDirectory(root,state,{current:valid,openChild,drafts,company,onUpdated,onAccountUpdated,onReady});
}

export function openPermissionAccountDirectory(state,{onBack,onUpdated,onAccountUpdated,companyId}){
 if(state.user?.role!=='admin'||!state.companies?.some(item=>Number(item.id)===Number(companyId)))return;
 const token=workspaceToken(state),drafts=createSettingsDraftGuard();
 const dialog=openDialog(t('Kullanıcı yönetimi'),html`<section data-permission-directory-host></section>`,{className:'customer-settings-dialog',draftGuard:drafts});
 const current=()=>dialog.open&&state.user?.role==='admin'&&sameWorkspace(state,token);
 const dispose=mountPermissionAccountDirectory(dialog.querySelector('[data-permission-directory-host]'),state,{companyId,current,drafts,onUpdated,onAccountUpdated});
 const back=addDialogBack(dialog,null,'Kullanıcı Yetkilerine dön');
 back.onclick=async()=>{
  if(!current()||!await canLeaveDialogDrafts()||!current())return;
  if(await onBack())dispose?.();
 };
 return dialog;
}
