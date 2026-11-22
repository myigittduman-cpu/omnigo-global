import {company,user,today,stamp,dashboard,analytics,paymentReport,shipmentRows,tickets,providers} from './data.js';
import {access,preferences,inventory,orders,orderDetail,returns,deliveries,cityProducts,stockHistory} from './workspace-data.js';
import {applyWorkspaceDesign} from '../workspace-design-state.js';
import {demoFinancialView} from './visibility.js';
import {activeShipmentExport} from '../shipment-export-rows.js';
export function setCsrf(){}
export function setOperatorToken(){}
const denied=()=>{const error=new Error('Demo yalnızca inceleme içindir. Değişiklikler, belge yüklemeleri ve mesajlar gönderilmez.');error.status=403;return error;};
export async function upload(){throw denied();}
export async function api(path,method='GET',data={}){
 const url=new URL(path,'https://demo.invalid'),route=url.pathname,q=url.searchParams;
 if(url.origin!=='https://demo.invalid')throw denied();
 if(q.has('company')&&q.get('company')!==String(company.id))throw denied();
 const readAction=method==='GET'||(method==='POST'&&['/shipments/search','/shipments/export'].includes(route));
 if(!readAction)throw denied();
 let result;
 if(route==='/status')result={needsSetup:false,demo:false,portalRole:'client'};
 else if(route==='/me')result={user,csrf:'',demo:false};
 else if(route==='/companies')result=[company];
 else if(route==='/account/preferences')result={preferences,version:0};
 else if(route==='/account/workspace-design')result={workspaceDesign:{mode:'balanced',version:0}};
 else if(route==='/dashboard')result={...dashboard(q.get('month')||today().slice(0,7)),workspaceAccess:access,modules:{shipments:true,deliveries:true},workspaceDesign:{mode:'balanced',version:0}};
 else if(route==='/inventory')result=inventory(q);
 else if(route==='/inventory/history')result=stockHistory(q);
 else if(route==='/orders')result=orders(q);
 else if(/^\/orders\/\d+$/.test(route))result=orderDetail(route.split('/').at(-1));
 else if(route==='/returns')result=returns(q);
 else if(route==='/city-products')result=cityProducts(q);
 else if(route==='/delivery/orders')result=deliveries(q);
 else if(route==='/analytics/report')result=analytics(q.get('from'),q.get('to'),q.get('channel')||'all');
 else if(route==='/payments/calendar')result=paymentReport(q.get('from'),q.get('to'));
 else if(route==='/sales/detail'){
  const report=analytics(q.get('from'),q.get('to'),q.get('channel')),products=report.products;
  result={...report,available:true,asOf:stamp(),topProducts:[...products].sort((a,b)=>b.netQuantity-a.netQuantity).slice(0,10),productCount:products.length,page:0,hasMore:false};
 }else if(route==='/shipments/revision')result={revision:'demo-1'};
 else if(['/shipments','/shipments/search','/shipments/export'].includes(route)){
  const month=q.get('month')||today().slice(0,7),provider=q.get('provider')||'all',city=q.get('city')||'all';
  if(!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)||!(provider==='all'||[...providers,'Hepsiburada','PTT AVM'].includes(provider)))throw denied();
  let items=(provider==='all'?providers.flatMap((name,index)=>shipmentRows(month,name).map(row=>({...row,id:row.id+index*100,provider:name}))):shipmentRows(month,provider)).filter(row=>city==='all'||row.province===city);
  const totalRecords=items.length,filters=data.filters||{},query=String(data.query||'').toLocaleLowerCase('tr-TR');
  items=items.filter(row=>(!query||Object.values(row).join(' ').toLocaleLowerCase('tr-TR').includes(query))&&Object.entries(filters).every(([key,value])=>!value||(key==='dateFrom'?row.orderedAt.slice(0,10)>=value:key==='dateTo'?row.orderedAt.slice(0,10)<=value:['invoiced','cancelled','shipped'].includes(key)?Boolean(row[key])===(value==='yes'):row[key]===value)));
  const pageSize=10,total=items.length,page=Math.min(Math.max(0,Number(q.get('page'))||0),Math.max(0,Math.ceil(total/pageSize)-1));
  result={company,city,provider,month,total,totalRecords,page,pageSize,hasMore:(page+1)*pageSize<total,revision:'demo-1',items:route==='/shipments/export'?items:items.slice(page*pageSize,(page+1)*pageSize),query,filters,undo:false,deleted:false};
  if(route==='/shipments/export')result=activeShipmentExport(result);
 }else if(route==='/support/tickets')result={items:tickets,hasMore:false};
 else if(/^\/support\/tickets\/\d+$/.test(route))result=tickets.find(ticket=>ticket.id===Number(route.split('/').at(-1)));
 else if(route==='/notifications')result={items:[],counts:{all:0,read:0,unread:0},hasMore:false};
 if(result===undefined)throw denied();
 if(['/dashboard','/inventory','/orders','/delivery/orders','/analytics/report','/payments/calendar','/sales/detail'].includes(route)||/^\/orders\/\d+$/.test(route)){
  const sales=['/dashboard','/analytics/report','/sales/detail'].includes(route);
  result={...demoFinancialView(result,sales),sensitiveVisibility:{cancellations:false,returns:false},...(sales?{turnoverDisplayBasis:'after_cancellations'}:{})};
  if(route==='/inventory')result.items=result.items.map(item=>({...item,sensitiveVisibility:result.sensitiveVisibility}));
 }
 applyWorkspaceDesign(result?.workspaceDesign);
 return structuredClone(result);
}
