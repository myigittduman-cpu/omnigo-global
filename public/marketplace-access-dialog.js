import {html} from './i18n.js';
import {isDialogCancel} from './dialog-cancel.js';
const covers=new Set();

/** A separate top-layer form keeps an expired grant from replacing the user's draft. */
export function marketplaceAccessDialog(content,isBusy){
 const prior=document.activeElement,dialog=document.createElement('dialog');
 dialog.className='marketplace-access-dialog';dialog.setAttribute('aria-label','Omnigo Admin');
 dialog.innerHTML=html`<div class="dialog-head"><h2>Omnigo Admin</h2><button type="button" class="close" aria-label="Kapat">×</button></div><div class="dialog-body">${content}</div>`;
 dialog.querySelector('.close').onclick=()=>{if(!isBusy())dialog.close();};
 dialog.addEventListener('cancel',event=>{if(!isDialogCancel(event,dialog)||isBusy())event.preventDefault();});
 dialog.addEventListener('close',()=>{dialog.remove();if(!dialog.dataset.accepted&&prior?.isConnected)prior.focus({preventScroll:true});},{once:true});
 document.body.append(dialog);dialog.showModal();return dialog;
}

/** The password cover stays in place until the destination has its final content. */
export function retainAccessCover(dialog){
 dialog.dataset.accepted='true';
 const complete=()=>{
  covers.delete(complete);
  document.removeEventListener('omnigo-dialog-ready',complete);
  dialog.close();
 };
 covers.add(complete);
 document.addEventListener('omnigo-dialog-ready',complete,{once:true});
 dialog.addEventListener('close',()=>{covers.delete(complete);document.removeEventListener('omnigo-dialog-ready',complete);},{once:true});
}

/** Release the cover on a failed load or when an existing view resumes. */
export function finishAccessCover(){
 const pending=[...covers];
 requestAnimationFrame(()=>{for(const complete of pending)complete();});
}
