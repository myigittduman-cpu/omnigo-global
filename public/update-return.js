import {UPDATE_RETURN_KEY,rememberUpdateReturn,pendingUpdateReturn as readUpdateReturn,takeUpdateReturn} from './update-return-state.js';
import {capturePagePosition,stagePagePosition,hasPageSnapshot} from './workspace-page-cache.js';
export const pendingUpdateReturn=(user,storage=sessionStorage)=>readUpdateReturn(user,storage);
let getState=()=>null,restoring=Promise.resolve(),consumed=false;
const tableSelectors=['.table-scroll','.inventory-table-wrap','.orders-table-wrap','.shipment-scroll','.file-center-table'];
export function registerUpdateContext(provider){getState=provider;}
export function captureUpdateReturn(storage=sessionStorage){
 const state=getState();if(!state?.user)return;
 const app=document.getElementById('app');
  const scroll={x:window.scrollX,y:window.scrollY,app:app?.scrollTop||0,tables:tableSelectors.flatMap(selector=>[...(app?.querySelectorAll(selector)||[])].map((element,index)=>({selector,index,left:element.scrollLeft})).filter(row=>row.left>0))};
 rememberUpdateReturn(storage,state,{position:capturePagePosition(state),scroll});
}
export function consumeUpdateReturn(user,companies,storage=sessionStorage){if(consumed)return null;const row=takeUpdateReturn(user,companies,storage);if(row)consumed=true;return row;}
export function prepareUpdatePosition(state,record){stagePagePosition(state,record.position);}
const frames=()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
export function restoreUpdatePosition(state,record){
 restoring=(async()=>{
  const app=document.getElementById('app'),same=()=>Number(state.user?.id)===record.userId&&state.user?.role===record.role&&(Number(state.company)||null)===record.companyId;
  if(!same())return;
  const paged=['inventory','orders','returns','shipments','deliveries','cityProducts','analytics','payments','connections','fileCenter'].includes(state.view);
  if(paged&&!hasPageSnapshot(state))await new Promise(resolve=>{let timer;const done=()=>{clearTimeout(timer);window.removeEventListener('omnigo-page-data-ready',ready);resolve();};const ready=event=>{if(same()&&event.detail?.user===record.userId&&event.detail?.company===record.companyId&&event.detail?.view===(state.view==='deliveries'?'delivery':state.view))done();};window.addEventListener('omnigo-page-data-ready',ready);timer=setTimeout(done,10000);});
  await Promise.race([Promise.all([...document.querySelectorAll('link[rel=stylesheet]')].map(link=>link.sheet?Promise.resolve():new Promise(resolve=>{link.addEventListener('load',resolve,{once:true});link.addEventListener('error',resolve,{once:true});}))),new Promise(resolve=>setTimeout(resolve,2500))]);
  if(document.fonts?.ready)await Promise.race([document.fonts.ready,new Promise(resolve=>setTimeout(resolve,1200))]);
  await frames();if(!same())return;
  const safe=value=>Number.isFinite(value)?Math.max(0,Math.min(10000000,value)):0;
  window.scrollTo({left:safe(record.scroll?.x),top:safe(record.scroll?.y),behavior:'instant'});app.scrollTop=safe(record.scroll?.app);
  for(const row of record.scroll?.tables||[])if(tableSelectors.includes(row.selector)&&Number.isInteger(row.index)&&row.index>=0&&row.index<50){const element=app.querySelectorAll(row.selector)[row.index];if(element)element.scrollLeft=safe(row.left);}
  await frames();
 })();return restoring;
}
export const waitForUpdateReturn=()=>restoring;
export function clearUpdateReturn(storage=sessionStorage){try{storage.removeItem(UPDATE_RETURN_KEY);}catch{}}
