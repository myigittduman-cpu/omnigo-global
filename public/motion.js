const running=new WeakMap();
export function motionReduced(root=globalThis.document?.documentElement){
 return root?.dataset?.reducedMotion==='true'||globalThis.matchMedia?.('(prefers-reduced-motion:reduce)').matches===true;
}
/** Paint at the final geometry. Never wait for an animation to bind controls. */
export function revealContent(element){
 if(!element?.animate||motionReduced(element.ownerDocument?.documentElement))return;
 running.get(element)?.cancel();
 const animation=element.animate([{opacity:.86},{opacity:1}],{duration:75,easing:'ease-out'});
 running.set(element,animation);
 animation.finished?.then(()=>{if(running.get(element)===animation)running.delete(element);},()=>{});
}
export function createPageMotion(){
 let previous;
 return (element,key)=>{if(key===previous)return;previous=key;revealContent(element);};
}
