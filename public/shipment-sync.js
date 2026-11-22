import {api} from './api.js';

// Short revision checks avoid repeatedly downloading or decrypting unchanged rows.
export function bindShipmentSync({root,model,current,endpoint,canRefresh,refresh,signal}){
 let running=false,stopped=false,failures=0,timer;
 const events=new AbortController();
 async function check(){
  if(stopped||running||!current()||document.hidden||!navigator.onLine||!canRefresh())return;
  running=true;
  try{
   const result=await api(endpoint('revision'));
   if(!current()||!canRefresh())return;
   if(result.revision!==model.revision){await refresh({silent:true,background:true});if(model.error)throw new Error('Sevkiyat listesi yenilenemedi.');}
   failures=0;
  }catch{failures=Math.min(failures+1,4);}
  finally{running=false;}
 }
 function schedule(){timer=setTimeout(async()=>{await check();if(!stopped)schedule();},Math.min(30000,1500*2**failures));}
 function stop(){stopped=true;clearTimeout(timer);events.abort();observer.disconnect();}
 const observer=new MutationObserver(()=>{if(!root.isConnected)stop();});
 observer.observe(document.body,{childList:true,subtree:true});
 signal?.addEventListener('abort',stop,{once:true});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)void check();},{signal:events.signal});
 window.addEventListener('online',()=>void check(),{signal:events.signal});
 schedule();return check;
}
