import {html,t} from './i18n.js';
import {esc} from './ui.js';
export function loadingState(message='İçerik yükleniyor…',{compact=false}={}){
 return html`<div class="content-state ${compact?'content-state--compact':''}" role="status" aria-busy="true"><span class="content-state-mark" aria-hidden="true"></span><p>${esc(t(message))}</p></div>`;
}
export function contentError(message){
 return html`<div class="content-state content-state--error" role="alert"><span class="content-state-mark" aria-hidden="true"></span><p>${esc(t(message))}</p></div>`;
}
const activeLoads=new WeakMap();
export function beginContentLoad(target,message){
 const pending=activeLoads.get(target);
 const load={previousMinSize:pending?.previousMinSize??target.style.minBlockSize};
 if(!pending){
  const height=target.getBoundingClientRect().height;
  target.style.minBlockSize=Math.max(180,Math.ceil(height))+'px';
 }
 activeLoads.set(target,load);
 target.setAttribute('aria-busy','true');
 if(!target.childElementCount||target.querySelector(':scope > .content-state'))target.innerHTML=loadingState(message);
 return ()=>{
  if(activeLoads.get(target)!==load)return;
  activeLoads.delete(target);
  target.style.minBlockSize=load.previousMinSize;
  target.removeAttribute('aria-busy');
 };
}
