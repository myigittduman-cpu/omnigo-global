import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const bindings=new WeakMap();
export function bindShipmentSidebar(app,root){
 bindings.get(app)?.();
 const sidebar=app.querySelector('.sidebar');if(!sidebar)return;
 const button=document.createElement('button');button.type='button';button.className='shipment-sidebar-toggle';
 const previousId=sidebar.id;sidebar.id='shipment-sidebar';button.setAttribute('aria-controls',sidebar.id);
 let closed=false,frame1=0,frame2=0;
 const desktop=matchMedia('(min-width:761px)');
 const apply=()=>{
  app.classList.toggle('shipment-sidebar-closed',closed);sidebar.inert=closed&&desktop.matches;
  button.setAttribute('aria-expanded',String(!closed));button.setAttribute('aria-label',closed?i18nT('Menüyü aç'):i18nT('Menüyü kapat'));button.title=closed?i18nT('Menüyü aç'):i18nT('Menüyü kapat');button.textContent=closed?'›':'‹';
  app.style.setProperty('--shipment-sidebar-width',sidebar.getBoundingClientRect().width+'px');
 };
 button.onclick=()=>{closed=!closed;apply();};desktop.addEventListener('change',apply);
 app.classList.add('shipment-layout');app.append(button);apply();
 frame1=requestAnimationFrame(()=>{frame2=requestAnimationFrame(()=>{if(!root.isConnected)return;closed=true;if(sidebar.contains(document.activeElement)&&desktop.matches)button.focus({preventScroll:true});apply();});});
 const observer=new MutationObserver(()=>{if(!root.isConnected)cleanup();});observer.observe(app,{childList:true,subtree:true});
 const cleanup=()=>{cancelAnimationFrame(frame1);cancelAnimationFrame(frame2);observer.disconnect();desktop.removeEventListener('change',apply);button.remove();sidebar.inert=false;sidebar.id=previousId;app.classList.remove('shipment-layout','shipment-sidebar-closed');app.style.removeProperty('--shipment-sidebar-width');if(bindings.get(app)===cleanup)bindings.delete(app);};
 bindings.set(app,cleanup);
}
