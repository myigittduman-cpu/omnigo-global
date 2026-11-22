import {api} from './api.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

// A small indexed revision lookup refreshes shared data only after a committed change.
export function startWorkspaceSync({state,isSignedIn,refresh,onIdentity=()=>{}}){
 let stopped=false,running=false,timer,failures=0,seen=null;
 const events=new AbortController();
 const blocked=()=>document.hidden||!navigator.onLine||document.querySelector('dialog[open],[data-shipments]')||document.activeElement?.matches('input,textarea,select,[contenteditable="true"]');
 async function check(){
  if(stopped||running||!isSignedIn()||document.hidden||!navigator.onLine)return;
  running=true;const token=workspaceToken(state),view=state.view,key=JSON.stringify([token,view,state.month]);
  try{
   const data=await api('/workspace/revision?company='+token.company);
   if(sameWorkspace(state,token))onIdentity(data.company);
   if(!sameWorkspace(state,token)||view!==state.view||blocked())return;
   if(!seen||seen.key!==key||seen.revision!==data.revision){await refresh();if(!sameWorkspace(state,token)||view!==state.view||blocked())return;seen={key,revision:data.revision};}
   failures=0;
  }catch{failures=Math.min(4,failures+1);}
  finally{running=false;}
 }
 const schedule=()=>{timer=setTimeout(async()=>{await check();if(!stopped)schedule();},Math.min(30000,1500*2**failures));};
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)void check();},{signal:events.signal});
 window.addEventListener('online',()=>void check(),{signal:events.signal});
 schedule();return ()=>{stopped=true;clearTimeout(timer);events.abort();};
}
