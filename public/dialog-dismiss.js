/** Route backdrop dismissal through cancel, preserving each dialog's draft/busy guard. */
export function installDialogDismiss(){
 if(typeof document==='undefined'||!document.documentElement||typeof MutationObserver==='undefined')return;
 const attached=new WeakSet();
 const bind=dialog=>{if(dialog.id==='dialog'||attached.has(dialog))return;attached.add(dialog);let outside=false;
  const isOutside=event=>{if(event.target!==dialog)return false;const r=dialog.getBoundingClientRect();return event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom;};
  dialog.addEventListener('pointerdown',event=>outside=event.button===0&&isOutside(event));
  dialog.addEventListener('pointercancel',()=>outside=false);
  dialog.addEventListener('click',event=>{const dismiss=outside&&isOutside(event);outside=false;if(!dismiss||!dialog.open)return;if(typeof dialog.requestClose==='function')dialog.requestClose();else if(dialog.dispatchEvent(new Event('cancel',{cancelable:true})))dialog.close();});
 };
 const scan=()=>document.querySelectorAll('dialog').forEach(bind);scan();new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true});
}
