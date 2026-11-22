import {readPendingUpdate} from './web-update-state.js';
import {shipmentDocumentPages} from './shipment-document-pages.js';
import {customerOverviewKeys} from './customer-overview-menu.js';
import {financeReportKeys} from './finance-report-pages.js';
export const UPDATE_RETURN_KEY='omnigo.update-return.v1';
const sharedViews=new Set(['overview',...customerOverviewKeys,'channels','orders','inventory','analytics','payments','tasks','reports','support','shipments','deliveries','cityProducts','returns',...Object.keys(shipmentDocumentPages)]);
const adminViews=new Set(['customers','fileCenter','connections','inbox',...financeReportKeys]);
function workspaceView(view,role){
 if(sharedViews.has(view)||role==='admin'&&adminViews.has(view))return view;
 return role==='admin'?'overview':'channels';
}
const day=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(value);
const positionFields=new Set(['query','filter','provider','category','sort','page','pageSize','city','cities','queue','from','to','status','search','month','filters','fileCenterTab']);
const resetPosition=record=>({...record,position:null,scroll:{x:0,y:0,app:0,tables:[]}});
/** Store navigation only. Credentials, record contents, drafts and row selections are never serialized. */
export function rememberUpdateReturn(storage,state,{position=null,scroll={x:0,y:0,app:0,tables:[]}}={},now=Date.now()){
 if(!state?.user?.id||!['admin','client'].includes(state.user.role))return;
 const companyId=Number(state.company)||null;
 const view=companyId?workspaceView(state.view,state.user.role):'entry';
 const range=state.dateRange;
 const record={userId:Number(state.user.id),role:state.user.role,companyId,view,createdAt:now,month:/^\d{4}-\d{2}$/.test(state.month)?state.month:null,
  dateRange:companyId&&Number(range?.company)===companyId&&day(range.from)&&day(range.to)?{company:companyId,from:range.from,to:range.to,automatic:Boolean(range.automatic)}:null,
  filter:['all','pending','paid'].includes(state.filter)?state.filter:'all',position:position&&typeof position==='object'?Object.fromEntries(Object.entries(position).filter(([key,value])=>positionFields.has(key)&&JSON.stringify(value)?.length<8000)):null,scroll};
 const encoded=JSON.stringify(companyId&&view!==state.view?resetPosition(record):record);if(encoded.length>16000)throw Error('Çalışma konumu güncelleme için kaydedilemedi.');storage.setItem(UPDATE_RETURN_KEY,encoded);
}
export function pendingUpdateReturn(user,storage,now=Date.now()){
 if(!readPendingUpdate(storage,now))return null;
 try{
  const row=JSON.parse(storage.getItem(UPDATE_RETURN_KEY));
  if(!row||!['admin','client'].includes(row.role)||row.userId!==Number(user?.id)||row.role!==user?.role||typeof row.view!=='string'||!Number.isFinite(row.createdAt)||now<row.createdAt||now-row.createdAt>=900000)return null;
  if(row.companyId!==null&&(!Number.isSafeInteger(row.companyId)||row.companyId<1))return null;
  if(row.companyId===null&&(row.role!=='admin'||row.view!=='entry'))return null;
  if(row.role!=='admin'&&row.companyId!==Number(user.companyId??user.company_id))return null;
  if(row.month!==null&&!/^\d{4}-(0[1-9]|1[0-2])$/.test(row.month))return null;
  if(row.dateRange&&(row.dateRange.company!==row.companyId||!day(row.dateRange.from)||!day(row.dateRange.to)||row.dateRange.from>row.dateRange.to))return null;
  if(!['all','pending','paid'].includes(row.filter)||!row.scroll||typeof row.scroll!=='object')return null;
  if(row.position!==null&&(typeof row.position!=='object'||Array.isArray(row.position)))return null;
  const view=row.companyId?workspaceView(row.view,row.role):'entry';
  return view===row.view?row:resetPosition({...row,view});
 }catch{return null;}
}
export function takeUpdateReturn(user,companies,storage,now=Date.now()){
 const row=pendingUpdateReturn(user,storage,now);
 if(!row||row.companyId!==null&&!companies.some(company=>Number(company.id)===row.companyId))return null;
 return row;
}
