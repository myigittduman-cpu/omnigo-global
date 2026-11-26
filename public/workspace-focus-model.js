import {customerOverviewKeys} from './customer-overview-menu.js';
import {financeReportKeys} from './finance-report-pages.js';
import {customPageGroup} from './custom-page-navigation.js';

/** Presentation groups only. The existing role and customer policy chooses the pages. */
export const workspaceFocusGroups=Object.freeze([
 Object.freeze({id:'management',label:'Yönetim',icon:'overview',pages:Object.freeze(['overview',...customerOverviewKeys,'customers','tasks','fileCenter'])}),
 Object.freeze({id:'operations',label:'Operasyon',icon:'inventory',pages:Object.freeze(['inbox','returns','inventory','shipments','deliveries','cityProducts','orders'])}),
 Object.freeze({id:'finance',label:'Finans ve analiz',icon:'analytics',pages:Object.freeze([...financeReportKeys,'analytics','payments','channels','reports'])}),
 Object.freeze({id:'system',label:'Destek ve sistem',icon:'connections',pages:Object.freeze(['support','connections'])})
]);

export const adminWorkspaceFocusGroups=Object.freeze(workspaceFocusGroups.map(group=>{
 if(group.id==='management')return Object.freeze({...group,pages:Object.freeze(['overview','customers','fileCenter','orders',...customerOverviewKeys,'tasks'])});
 if(group.id==='operations')return Object.freeze({...group,pages:Object.freeze(group.pages.filter(page=>page!=='orders'))});
 return group;
}));

export function workspaceFocusCategory(page){
 if(customPageGroup(page))return customPageGroup(page);
 if(page==='marketplaceStocks')return 'operations';
 return workspaceFocusGroups.find(group=>group.pages.includes(page))?.id||'management';
}

export function workspaceFocusModel(pages,{view,selectedCategory}={}){
 const available=new Set(pages.map(workspaceFocusCategory));
 const groups=workspaceFocusGroups.filter(group=>available.has(group.id));
 const activeCategory=workspaceFocusCategory(view);
 const selected=groups.find(group=>group.id===selectedCategory)?.id||groups.find(group=>group.id===activeCategory)?.id||groups[0]?.id||'';
 return {groups,selected};
}
