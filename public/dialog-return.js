import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {t} from './i18n.js';

/** Wait for the old dialog's close handlers before reusing the shared dialog node. */
export async function closeDialogThen(dialog,next){
 if(!await canLeaveDialogDrafts())return false;
 dialog.dispatchEvent(new Event('omnigo-dialog-navigation'));
 await new Promise(resolve=>{dialog.addEventListener('close',resolve,{once:true});dialog.close();});
 await next?.();return true;
}
export function addDialogBack(dialog,next,label='Geri'){
 const button=document.createElement('button');button.type='button';button.className='btn dialog-back';button.textContent='← '+t(label);
 dialog.querySelector('.dialog-body').prepend(button);button.onclick=async()=>{if(next?.restoresDialog){if(await canLeaveDialogDrafts())next();}else await closeDialogThen(dialog,next);};return button;
}
