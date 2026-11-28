import {isShipmentDocumentPage} from './shipment-document-pages.js';
import {customerOverviewMenu} from './customer-overview-menu.js';
import {usesCustomerWorkspaceStandard} from './customer-workspace-standard.js';
import {isFinanceReportPage} from './finance-report-pages.js';
import {isCustomPageView,customPageForView,customPageItems} from './custom-page-navigation.js';

import {canUseMarketplaceStockPilot,marketplaceStockPage} from './marketplace-stock-pilot.js';

const tableDataActions=new Set(['viewCancelledRecords','viewReturnedRecords','viewAfterCancellationTurnover','viewCancelledStatus']);

export function pendingWorkTargets(state){
 return ['shipmentInvoices','deliveries'].filter(page=>
  customerPageVisible(state,page)&&(page==='deliveries'||(customerCan(state,page,'documents')&&customerCan(state,page,'upload'))));
}

/** Mirrors the server policy for presentation; server checks remain authoritative. */
export function customerCan(state,page,action='view'){
 if(isCustomPageView(page)||page==='customPages')return action==='view'&&(page==='customPages'?customPageItems(state).length>0:Boolean(customPageForView(state,page)));
 if(page===marketplaceStockPage)return action==='view'&&canUseMarketplaceStockPilot(state);
 if(page==='orders'&&action==='view'&&state.user?.role==='admin'&&Number(state.company)>0)return true;
 if(page==='inbox'||isFinanceReportPage(page)&&page!=='financeProfitability')return state.user?.role==='admin'&&action==='view';
 if(page==='financeProfitability'&&state.user?.role==='admin')return ['view','export'].includes(action);
 const access=state.data?.workspaceAccess;
 if(page==='shipmentDeliveryDocuments')return false;
 if(page==='cityProducts')return access?.pages?.[page]==='shown'&&access.grants?.[page]?.view===true&&access.grants[page][action]===true;
 if(access?.setupOptions&&(access.pages?.[page]==='hidden'||access.grants?.[page]?.[action]===false))return false;
 if(tableDataActions.has(action)){
  return state.user?.role==='admin'||(access?.pages?.[page]==='shown'&&access.grants?.[page]?.view===true&&access.grants[page][action]===true);
 }
 if(page==='support'&&state.user?.role==='client'&&access?.storeRole==='accounting_logistics'&&!usesCustomerWorkspaceStandard(state))return false;
 if(isShipmentDocumentPage(page)){
  if(state.user?.role==='admin')return true;
  if(!access?.grants?.[page])return customerCan(state,'shipments',['view','documents'].includes(action)?'documents':action);
  return access.pages?.[page]==='shown'&&access.grants[page].view===true&&access.grants[page][action]===true;
 }
 if(Object.hasOwn(customerOverviewMenu,page)){
  if(state.user?.role==='admin')return page==='overviewWork'&&action==='view';
  if(state.user?.role!=='client'||action!=='view')return false;
  if(access?.grants?.[page]){
   return access.pages?.[page]==='shown'&&access.grants[page].view===true&&
    (page!=='overviewWork'||pendingWorkTargets(state).length>0);
  }
  if(!customerCan(state,'overview'))return false;
  const sections=state.data?.workspaceAccess?.overviewSections;
  if(Array.isArray(sections)&&!sections.includes(customerOverviewMenu[page].section))return false;
  return page!=='overviewWork'||pendingWorkTargets(state).some(key=>key!=='deliveries');
 }
 if(state.user?.role==='admin')return true;
 if(page==='visibility')return false;
 if(page==='returns'&&!customerCan(state,page,'viewCancelledRecords')&&!customerCan(state,page,'viewReturnedRecords'))return false;
 if(access)return access.pages?.[page]==='shown'&&access.grants?.[page]?.view===true&&access.grants?.[page]?.[action]===true;
 if(action==='view')return !['overview','orders','returns','customers','connections','fileCenter','financeProfitability'].includes(page);
 return page==='support'||action==='export'&&['analytics','reports'].includes(page);
}
export function customerPageVisible(state,page){
 if(page==='orders'&&state.user?.role==='admin'&&Number(state.company)>0)return true;
 if(isFinanceReportPage(page))return customerCan(state,page);
 if(page==='overviewWork'&&state.user?.role==='admin')return true;
 if(isShipmentDocumentPage(page)){
  const access=state.data?.workspaceAccess;
  if(!access?.grants?.[page])return customerPageVisible(state,'shipments')&&customerCan(state,page);
  return state.data?.modules?.shipments!==false&&access.pages?.[page]==='shown'&&customerCan(state,page);
 }
 return (!['shipments','deliveries'].includes(page)||state.data?.modules?.[page]!==false)&&
  (!state.data?.workspaceAccess?.pages?.[page]||state.data.workspaceAccess.pages[page]==='shown')&&customerCan(state,page);
}

export function salesWorkspacePreset(configuration){
 const next=structuredClone(configuration),shown=['overview','channels','orders','analytics','support'];
 for(const page of Object.keys(next.pages)){
  if(page==='visibility'){next.pages[page]='shown';next.defaults[page].view=true;continue;}
  next.pages[page]=shown.includes(page)?'shown':'hidden';
  next.defaults[page].view=shown.includes(page);
 }
 next.pages.shipments='removed';next.pages.deliveries='removed';
 next.notifications=Object.fromEntries(['newOrder','cancellation','transfer','shipment','support','connections','users'].map(key=>[key,['newOrder','cancellation'].includes(key)]));
 return next;
}
