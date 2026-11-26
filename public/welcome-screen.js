/* One welcome per document launch. It never runs again during page/dialog navigation. */
document.documentElement.dataset.theme||='classic-navy';
(()=>{
 const welcome=document.getElementById('omnigoWelcome'),app=document.getElementById('app');if(!welcome||!app)return;
 // Android's first native frame already owns the five-second welcome; its secure bridge arrives later.
 if(window.__OMNIGO_NATIVE_WELCOME__||/\bOmnigoAndroid\/\d/.test(navigator.userAgent)){welcome.remove();return;}
 const fill=welcome.querySelector('.omnigo-welcome-progress>span');
 const status=welcome.querySelector('[data-welcome-status]');
 document.documentElement.classList.add('omnigo-welcome-open');app.inert=true;
 void import('./transition-ambient.js').then(({addTransitionAmbient})=>{
  if(welcome.isConnected)addTransitionAmbient(welcome);
 }).catch(()=>{});
 let started=false,ready=false,finished=false,frame=0,fallback;
 const finish=()=>{if(finished)return;finished=true;cancelAnimationFrame(frame);clearTimeout(fallback);welcome.remove();app.inert=false;document.documentElement.classList.remove('omnigo-welcome-open');window.dispatchEvent(new Event('omnigo-welcome-complete'));};
 const start=()=>{
  if(started||document.visibilityState==='hidden')return;
  if(window.__OMNIGO_STARTUP_GATE__&&document.documentElement.dataset.omnigoApplicationReady!=='true')return;
  started=true;const began=performance.now();
  const tick=time=>{
   const elapsed=time-began;
   if(fill)fill.style.transform='scaleX('+Math.min(1,Math.max(0,elapsed/5000))+')';
   if(status&&!ready&&elapsed>=4400){
    ready=true;
    status.textContent='Çalışma alanınız hazır';
    welcome.classList.add('omnigo-welcome-ready');
   }
   if(ready&&elapsed>=4860)welcome.classList.add('omnigo-welcome-leaving');
   if(elapsed<5000)frame=requestAnimationFrame(tick);else finish();
  };
  frame=requestAnimationFrame(tick);
 };
 window.addEventListener('omnigo-application-ready',()=>requestAnimationFrame(start),{once:true});
 document.addEventListener('visibilitychange',start);
 // Native reveal has its own bounded offline fallback. Never strand the browser behind a welcome.
 fallback=setTimeout(()=>{if(!started){window.removeEventListener('omnigo-application-ready',start);finish();}},12000);
 requestAnimationFrame(start);
})();
