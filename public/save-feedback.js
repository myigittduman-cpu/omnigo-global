import {t} from './i18n.js';
import {saveFeedbackFor} from './save-feedback-policy.js';
let timer,hideTimer,displayFrame,layerObserver,lastCommittedAt=0;const completed=new Set();
const saveScopes=[];
export function beginSaveFeedback(){const scope={feedback:null};saveScopes.push(scope);return scope;}
export function finishSaveFeedback(scope,saved){const index=saveScopes.indexOf(scope);if(index>=0)saveScopes.splice(index,1);if(saved&&scope.feedback)deliverFeedback(scope.feedback);}
export function showFeedback(message,{kind,force=false}={}){
 if(typeof document==='undefined')return;
 let node=document.getElementById('toast');
 if(!node){node=document.createElement('div');node.id='toast';document.body.append(node);}
 kind??=/kaydedildi|güncellendi|oluşturuldu|yüklendi|eklendi|silindi/i.test(message)?'success':'info';
 if(kind==='success'&&saveScopes.at(-1)?.feedback?.kind==='success')return;
 // A page-specific toast must not replace the shared committed-save confirmation.
 if(!force&&kind==='success'&&message!=='Bilgileriniz kaydedildi.'&&Date.now()-lastCommittedAt<10000)return;
 clearTimeout(timer);clearTimeout(hideTimer);layerObserver?.disconnect();
 if(displayFrame!==undefined&&typeof cancelAnimationFrame==='function')cancelAnimationFrame(displayFrame);
 node.className='show omnigo-feedback omnigo-feedback--'+kind;node.setAttribute('role','status');node.setAttribute('aria-live','polite');
 node.replaceChildren();
 if(kind==='success'){
  const mark=document.createElementNS('http://www.w3.org/2000/svg','svg');mark.setAttribute('viewBox','0 0 24 24');mark.setAttribute('aria-hidden','true');
  const ring=document.createElementNS(mark.namespaceURI,'circle');ring.setAttribute('cx','12');ring.setAttribute('cy','12');ring.setAttribute('r','10');
  const check=document.createElementNS(mark.namespaceURI,'path');check.setAttribute('d','m7 12 3 3 7-7');mark.append(ring,check);node.append(mark);
 }
 const text=document.createElement('span');text.textContent=t(message);node.append(text);
 // Keep the card in the current modal layer; a saved form may reopen its dialog.
 const present=(raise=false)=>{
  const host=[...document.querySelectorAll('dialog[open]')].at(-1)||document.body;
  const moving=node.parentElement!==host;
  if(typeof node.showPopover==='function'){
   if((moving||raise)&&node.matches(':popover-open'))node.hidePopover();
   if(moving)host.append(node);
   node.setAttribute('popover','manual');if(!node.matches(':popover-open'))node.showPopover();
  }else if(moving)host.append(node);
 };
 present();
 if(typeof MutationObserver!=='undefined'){
  layerObserver=new MutationObserver(records=>{if(node.classList.contains('show'))present(records.some(record=>record.type==='attributes'&&record.target.tagName==='DIALOG'));});
  layerObserver.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']});
 }
 const startDismiss=()=>{displayFrame=undefined;timer=setTimeout(()=>{layerObserver?.disconnect();node.classList.remove('show');hideTimer=setTimeout(()=>{if(typeof node.hidePopover==='function'&&node.matches(':popover-open'))node.hidePopover();},160);},3200);};
 // Background WebViews may delay painting while the saved dialog is rebuilt.
 // Count the display duration after an actual frame, rather than during that delay.
 if(typeof requestAnimationFrame==='function')displayFrame=requestAnimationFrame(startDismiss);else startDismiss();
}
export function reportSaveFeedback(path,method,result,data){
 const feedback=saveFeedbackFor(path,method,result,data);if(!feedback)return;
 if(feedback.key){if(completed.has(feedback.key))return;completed.add(feedback.key);if(completed.size>100)completed.delete(completed.values().next().value);}
 const scope=method==='GET'?null:saveScopes.at(-1);if(scope){scope.feedback=feedback;return;}
 deliverFeedback(feedback);
}
function deliverFeedback(feedback){if(feedback.kind==='success'){lastCommittedAt=Date.now();feedback.message='Bilgileriniz kaydedildi.';}showFeedback(feedback.message,feedback);}
