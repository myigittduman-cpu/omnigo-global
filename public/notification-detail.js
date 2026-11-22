import {openDialog} from './ui.js';
import {loadingState,contentError} from './content-state.js';
import {sameWorkspace} from './workspace-context.js';
const owners=new WeakMap();
export const notificationDialogClass=type=>({order:'order-detail-dialog',operation:'event-notification-dialog',shipment:'shipment-notification-dialog',support:'support-detail-dialog',user:'support-detail-dialog',resolved:'support-detail-dialog'})[type];

/** Allocate the final frame before requesting data; never update a replaced or closed view. */
export function notificationDetailShell(state,token,title,className='event-notification-dialog',{host=null}={}){
 const dialog=host||openDialog(title,loadingState('Ayrıntılar yükleniyor…'),{className:`notification-detail-dialog ${className}`});
 const body=dialog.querySelector('.dialog-body'),abort=new AbortController(),owner={abort};let closed=false;
 owners.get(dialog)?.dispose();owners.set(dialog,owner);
 if(host)body.innerHTML=loadingState('Ayrıntılar yükleniyor…');
 const active=()=>!closed&&dialog.open&&owners.get(dialog)===owner&&dialog.querySelector('.dialog-body')===body&&sameWorkspace(state,token);
 // A queued close event from the previous detail can arrive after this frame reopens.
 const onClose=()=>{if(!dialog.open){closed=true;owner.dispose();}};
 owner.dispose=()=>{abort.abort();dialog.removeEventListener('close',onClose);};
 dialog.addEventListener('close',onClose);
 return {dialog,body,signal:abort.signal,active,show(markup){if(active())body.innerHTML=markup;},title(value){if(active())dialog.querySelector('.dialog-head h2').textContent=value;},fail(error){if(active()&&error.name!=='AbortError')body.innerHTML=contentError(error.message);}};
}

export async function loadNotificationDetail(state,token,{title,className,request,render,titleFor,read}){
 const shell=notificationDetailShell(state,token,title,className);
 try{
  const detail=await request(shell.signal);if(!shell.active())return shell.dialog;
  if(titleFor)shell.title(titleFor(detail));shell.show(render(detail));
  if(read&&shell.active())try{await read();}catch{if(shell.active())shell.body.insertAdjacentHTML('beforeend',contentError('Bildirim okundu olarak kaydedilemedi.'));}
 }catch(error){shell.fail(error);}
 return shell.dialog;
}
