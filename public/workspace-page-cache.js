import {workspaceToken} from './workspace-context.js';
const pages=new Map(),updatePositions=new Map();const lifetime=30000,limit=24;
const positionFields=new Set(['query','filter','provider','category','sort','page','pageSize','city','cities','queue','from','to','status','search','month','filters']);
const positionKey=(state,view)=>JSON.stringify([Number(state.user?.id),state.user?.role,Number(state.company),view==='deliveries'?'delivery':view]);
const cleanPosition=value=>Object.fromEntries(Object.entries(value||{}).filter(([field,item])=>positionFields.has(field)&&JSON.stringify(item)?.length<8000));
/** Memory only. No drafts, selections, credentials or cross-customer snapshots. */
export function pageSnapshot(state,view,fields){
 const token=workspaceToken(state),key=JSON.stringify([token,state.user?.role,state.data?.workspaceAccess?.version,state.month,state.dateRange||null,view]);
 return {
  available(){const item=pages.get(key);return updatePositions.has(positionKey(state,view))||Boolean(item&&Date.now()-item.at<=lifetime);},
  restore(model){const pendingKey=positionKey(state,view),pending=updatePositions.get(pendingKey);if(pending){updatePositions.delete(pendingKey);Object.assign(model,structuredClone(pending));return true;}const item=pages.get(key);if(!item||Date.now()-item.at>lifetime){pages.delete(key);return false;}Object.assign(model,structuredClone(item.value));return true;},
  save(model){const value={};for(const field of fields)if(Object.hasOwn(model,field))value[field]=model[field];pages.delete(key);pages.set(key,{at:Date.now(),value:structuredClone(value)});while(pages.size>limit)pages.delete(pages.keys().next().value);if(typeof window!=='undefined')window.dispatchEvent(new CustomEvent('omnigo-page-data-ready',{detail:{user:state.user?.id,company:Number(state.company),view}}));}
 };
}
export function clearPageSnapshots(){pages.clear();updatePositions.clear();}
export function clearMenuSnapshot(state,view){
 const scope=positionKey(state,view);
 for(const key of pages.keys()){
  const [token,role,,,,cachedView]=JSON.parse(key);
  if(positionKey({user:{id:token.user,role},company:token.company},cachedView)===scope)pages.delete(key);
 }
 updatePositions.delete(scope);
}
export function capturePagePosition(state){
 for(const [key,item] of [...pages].reverse()){const [token,role,,,,view]=JSON.parse(key);if(positionKey({user:{id:token.user,role},company:token.company},view)===positionKey(state,state.view))return cleanPosition(item.value);}
 return null;
}
export function stagePagePosition(state,position){if(position&&typeof position==='object'&&!Array.isArray(position))updatePositions.set(positionKey(state,state.view),cleanPosition(position));}
export function hasPageSnapshot(state){return [...pages.keys()].some(key=>{const [token,role,,,,view]=JSON.parse(key);return positionKey({user:{id:token.user,role},company:token.company},view)===positionKey(state,state.view);});}
