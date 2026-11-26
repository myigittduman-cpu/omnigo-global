import {pageSnapshot} from './workspace-page-cache.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {monthDateRange} from './filter-date-range.js';
import {now} from './ui.js';
import {customerCan} from './customer-access.js';
export function navigationRead(state,view,today=now()){
 const company=Number(state.company);if(!company)return null;
 const prefix=state.user?.role==='admin'?'/admin':'';
 if(view==='orders'&&prefix){
  const model={query:'',provider:'all',province:'all',page:0};
  return {cache:'managementOrders',model,path:'/admin/orders?'+new URLSearchParams({company,view:'management',...model})};
 }
 if(view==='orders'){
  const model={provider:'all',query:'',...monthDateRange(today),page:0};
  return {model,path:prefix+'/orders?'+new URLSearchParams({company,...model})};
 }
 if(view==='inventory'){
  const model={query:'',filter:'stocked',provider:'',category:'',sort:'stable',page:0,pageSize:10};
  return {model,path:'/inventory?'+new URLSearchParams({company,...model})};
 }
 if(view==='returns'){
  const cancelled=customerCan(state,'returns','viewCancelledRecords'),returned=customerCan(state,'returns','viewReturnedRecords');
  if(!cancelled&&!returned)return null;
  const model={provider:'all',type:cancelled&&returned?'all':cancelled?'cancellation':'return',status:'all',search:'',page:0};
  return {model,path:prefix+'/returns?'+new URLSearchParams({company,...model})};
 }
 if(view==='cityProducts'){
  const model={city:'all',sort:'newest',page:0};
  return {model,path:'/city-products?'+new URLSearchParams({company,...model,group:'order'})};
 }
 if(view==='fileCenter'&&prefix&&!state.demo){
  const setup=state.data?.workspaceAccess?.setupOptions;
  const fileCenterTab=!setup||setup.live.includes('fileCenter')?'products':'archive';
  const filters={query:'',brand:'',sort:'stable'},model={fileCenterTab,page:0,...filters};
  return {model,path:'/file-center/'+fileCenterTab+'?'+new URLSearchParams({company,page:0,...(fileCenterTab==='products'?filters:{})})};
 }
 return null;
}
/** One bounded, read-only request on navigation intent. No queue writes or API keys. */
export function createNavigationPrefetch({state,request,canOpen}){
 let running=false;const warmed=new Map();
 return async view=>{
  const connection=globalThis.navigator?.connection;
  if(running||view===state.view||!state.data||!canOpen(view)||globalThis.navigator?.onLine===false||connection?.saveData||['slow-2g','2g'].includes(connection?.effectiveType))return false;
  const plan=navigationRead(state,view);if(!plan)return false;
  const token=workspaceToken(state),access=state.data.workspaceAccess?.version;
  const key=JSON.stringify([token,state.user?.role,access,state.month,state.dateRange,view]);
  if(Date.now()-(warmed.get(key)||0)<25000)return false;
  const snapshot=pageSnapshot(state,plan.cache??view,['data',...Object.keys(plan.model)]);
  if(snapshot.available())return false;
  running=true;
  try{
   const data=await request(plan.path);
   if(!sameWorkspace(state,token)||state.view===view||!canOpen(view)||state.data?.workspaceAccess?.version!==access||snapshot.available())return false;
   snapshot.save({...plan.model,data});warmed.set(key,Date.now());
   while(warmed.size>12)warmed.delete(warmed.keys().next().value);
   return true;
  }catch{return false;}finally{running=false;}
 };
}
export function bindNavigationPrefetch(doc,options){
 const warm=createNavigationPrefetch(options);let timer;
 const selected=event=>event.target?.closest?.('button[data-nav]')?.dataset.nav;
 doc.addEventListener('pointerover',event=>{const view=selected(event);clearTimeout(timer);if(view)timer=setTimeout(()=>void warm(view),90);},{passive:true});
 doc.addEventListener('focusin',event=>{const view=selected(event);if(view)void warm(view);});
 doc.addEventListener('pointerout',event=>{if(selected(event)!==selected({target:event.relatedTarget}))clearTimeout(timer);},{passive:true});
 doc.addEventListener('pointerdown',()=>clearTimeout(timer),{passive:true});
}
