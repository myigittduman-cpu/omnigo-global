import {suspendDialogDrafts} from './dialog-drafts.js';
import {revealContent} from './motion.js';
const dimensions=new WeakMap();
function restoreStyle(dialog,style){
 if(style===null)dialog.removeAttribute('style');else dialog.setAttribute('style',style);
}
export function resetPreservedDialogSize(dialog){
 if(!dimensions.has(dialog))return;
 const style=dimensions.get(dialog);dimensions.delete(dialog);
 restoreStyle(dialog,style);
}

/** A child replaces content, never closes/reopens the top-layer dialog. */
export function preserveDialogView(dialog){
 const nodes=[...dialog.childNodes],className=dialog.className,style=dialog.getAttribute('style');
 const body=dialog.querySelector('.dialog-body'),scroll=body?.scrollTop||0,focus=document.activeElement;
 const handlers=Object.fromEntries(['onclick','oncancel','onpointerdown','onpointercancel'].map(name=>[name,dialog[name]]));
 const drafts=suspendDialogDrafts(dialog),box=dialog.getBoundingClientRect();let finished=false;
 const nested=dimensions.has(dialog);
 if(!nested)dimensions.set(dialog,style);
 dialog.style.width=`min(${box.width}px, calc(100vw - 24px))`;
 dialog.style.height=`min(${box.height}px, calc(100dvh - var(--desktop-bar-height, 0px) - 24px))`;
 const closed=()=>{if(finished)return;finished=true;drafts.dispose();};
 dialog.addEventListener('close',closed,{once:true});
 const restore=()=>{
  if(finished||!dialog.open)return false;finished=true;dialog.removeEventListener('close',closed);
  drafts.restore();dialog.className=className;
  if(nested)restoreStyle(dialog,style);else resetPreservedDialogSize(dialog);
  dialog.replaceChildren(...nodes);Object.assign(dialog,handlers);
  if(body)body.scrollTop=scroll;if(focus?.isConnected)focus.focus({preventScroll:true});
  revealContent(body);return true;
 };
 restore.restoresDialog=true;return restore;
}
