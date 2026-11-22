import {t as i18nT} from './i18n.js';
import {requestWebUpdate,resumeWebUpdate,updateWorkInProgress} from './web-update.js';
// Refresh customer data without interrupting forms or overlapping requests.
export function startLiveRefresh({isSignedIn,refresh,notify}) {
 let running=false,failures=0;
 const display=message=>{const target=document.getElementById('connectionStatus');if(target){target.textContent=message;target.hidden=!message;}};
 async function tick(){
  if(running||!isSignedIn()||document.hidden||document.querySelector('dialog[open]'))return;
  if(!navigator.onLine){display(i18nT('İnternet bağlantısı yok · Veriler güncel olmayabilir.'));return;}
  running=true;
  try{
   await refresh();failures=0;display('');
  }catch(error){
   failures++;
   display(i18nT('Veriler yenilenemedi · Son görünen bilgiler güncel olmayabilir.'));
   if(error.status===401)notify(i18nT('Oturumunuz sona erdi. Yeniden giriş yapın.'));
  }finally{running=false;}
 }
 let timer;
 const schedule=()=>{timer=setTimeout(async()=>{await tick();schedule();},Math.min(120000,30000*2**Math.min(failures,2)));};
 schedule();
 window.addEventListener('online',tick);
 window.addEventListener('offline',()=>display(i18nT('İnternet bağlantısı yok · Veriler güncel olmayabilir.')));
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick();});
 return ()=>clearTimeout(timer);
}

export async function watchWebVersion(){
 await resumeWebUpdate();
 let initial=null,shown=false,pending=null,prompting=false,promptTimer;
 async function prompt(){
  if(!pending||shown||prompting||document.hidden||updateWorkInProgress())return false;
  prompting=true;
  try{shown=await requestWebUpdate(pending);if(shown){clearInterval(promptTimer);document.removeEventListener('click',beforeAction,true);document.removeEventListener('keydown',beforeAction,true);}return shown;}
  finally{prompting=false;}
 }
 function beforeAction(event){
  if(!pending||shown||prompting||updateWorkInProgress())return;
  if(event.type==='keydown'&&!['Enter',' '].includes(event.key))return;
  event.preventDefault();event.stopImmediatePropagation();void prompt();
 }
 async function check(){
  if(document.hidden||!navigator.onLine||shown)return;
  try{
   const response=await fetch('/version.json',{cache:'no-store',credentials:'omit'});
   if(!response.ok)return;
   const release=await response.json();
   if(typeof release.version!=='string')return;
   if(initial===null){initial=release.version;return;}
   if(initial===release.version)return;
   pending=release.version;
   if(!promptTimer){promptTimer=setInterval(()=>void prompt(),500);document.addEventListener('click',beforeAction,true);document.addEventListener('keydown',beforeAction,true);}
   await prompt();
  }catch{/* Network failures must not interrupt the current view. */}
 }
 await check();setInterval(check,60000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();});
}
