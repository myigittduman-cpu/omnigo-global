let readiness;
const bounded=(promise,ms)=>new Promise(resolve=>{const timer=setTimeout(resolve,ms);Promise.resolve(promise).then(value=>{clearTimeout(timer);resolve(value);},()=>{clearTimeout(timer);resolve();});});
function stylesheetReady(link){
 if(link.sheet)return Promise.resolve();
 return bounded(new Promise(resolve=>{link.addEventListener('load',resolve,{once:true});link.addEventListener('error',resolve,{once:true});}),1800);
}
/** Signal once, after the real screen and its controls/styles replace the startup placeholder. */
export function markStartupReady(){
 if(readiness)return readiness;
 if(document.documentElement.dataset.omnigoApplicationReady==='true')return Promise.resolve();
 const finish=()=>{
  if(document.querySelector('#app .startup-screen')){readiness=null;return;}
  document.documentElement.dataset.omnigoApplicationReady='true';
  window.dispatchEvent(new Event('omnigo-application-ready'));
 };
 if(!window.__OMNIGO_DESKTOP__){finish();return Promise.resolve();}
 readiness=(async()=>{
  if(window.__OMNIGO_STARTUP_GATE__&&document.documentElement.dataset.omnigoDesktopChromeReady!=='true')await bounded(new Promise(resolve=>window.addEventListener('omnigo-desktop-chrome-ready',resolve,{once:true})),2500);
  await Promise.all([...document.querySelectorAll('link[rel=stylesheet]')].map(stylesheetReady));
  if(document.fonts?.ready)await bounded(document.fonts.ready,1200);
  // A hidden native window may throttle RAF. The bounded timer keeps offline startup usable.
  await bounded(new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))),120);
  finish();
 })();
 return readiness;
}
