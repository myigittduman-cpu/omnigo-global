import {pageSnapshot} from './workspace-page-cache.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {monthDateRange} from './filter-date-range.js';
import {now} from './ui.js';
export function navigationRead(state,view,today=now()){
 const company=Number(state.company);if(!company)return null;
 const prefix=state.user?.role==='admin'?'/admin':'';
 if(view==='orders'){
  const model={provider:'all',query:'',...monthDateRange(today),page:0};
  return {model,path:prefix+'/orders?'+new URLSearchParams({company,...model})};
 }
 if(view==='inventory'){
  const model={query:'',filter:'stocked',provider:'',category:'',sort:'sku',page:0,pageSize:10};
  return {model,path:'/inventory?'+new URLSearchParams({company,...model})};
 }
 if(view==='returns'){
  const model={provider:'all',status:'all',search:'',page:0};
  return {model,path:prefix+'/returns?'+new URLSearchParams({company,...model})};
 }
 return null;
}
/** One bounded, read-only request on navigation intent. No queue writes or API keys. */
export function createNavigationPrefetch({state,request,canOpen}){
 let running=false;const warmed=new Map();
 return async view=>{
  if(running||view===state.view||!state.data||!canOpen(view)||globalThis.navigator?.onLine===false||globalThis.navigator?.connection?.saveData)return false;
  const plan=navigationRead(state,view);if(!plan)return false;
  const token=workspaceToken(state),access=state.data.workspaceAccess?.version;
  const key=JSON.stringify([token,state.user?.role,access,state.month,state.dateRange,view]);
  if(Date.now()-(warmed.get(key)||0)<25000)return false;
  const snapshot=pageSnapshot(state,view,['data',...Object.keys(plan.model)]);
  if(snapshot.available())return false;
  running=true;
  try{
   const data=await request(plan.path);
   if(!sameWorkspace(state,token)||state.data?.workspaceAccess?.version!==access)return false;
   snapshot.save({...plan.model,data});warmed.set(key,Date.now());
   while(warmed.size>12)warmed.delete(warmed.keys().next().value);
   return true;
  }catch{return false;}finally{running=false;}
 };
}
export function bindNavigationPrefetch(doc,options){
 const warm=createNavigationPrefetch(options);let timer;
 const selected=event=>event.target.closest?.('button[data-nav]')?.dataset.nav;
 doc.addEventListener('pointerover',event=>{const view=selected(event);clearTimeout(timer);if(view)timer=setTimeout(()=>void warm(view),120);},{passive:true});
 doc.addEventListener('focusin',event=>{const view=selected(event);if(view)void warm(view);});
 doc.addEventListener('pointerdown',event=>{clearTimeout(timer);const view=selected(event);if(view)void warm(view);},{passive:true});
}
