import {t} from './i18n.js';
import {esc} from './ui.js';
import {adminWorkspaceFocusGroups} from './workspace-focus-model.js';
import {customerOverviewMenu} from './customer-overview-menu.js';
import {financeReportPages} from './finance-report-pages.js';
import {shipmentDocumentPages} from './shipment-document-pages.js';
import {customerWorkspaceDefaultLabel} from './customer-workspace-standard.js';

export const workspaceMenuLabelLimit=48;
const defaultItems={
 overview:'Genel bakış',customers:'Mağazalar',fileCenter:'Dosya Merkezi',tasks:'Çalışma takibi',
 channels:'Pazaryerlerim',orders:'Siparişler',inventory:'Stok Yönetimi',analytics:'Analiz ve Raporlar',
 payments:'Hakedişlerim',reports:'Raporlarım',support:'Destek talepleri',returns:'İptal Talepleri',
 shipments:'Şehir Dışı Sevkiyatlar',deliveries:'Şehir Dışı Teslimatlar',
 shipmentInvoices:shipmentDocumentPages.shipmentInvoices.name,cityProducts:'Şehir Dışı Ürünler',
 inbox:'Pazaryeri Soruları',connections:'Bağlantı kontrolü',
 ...Object.fromEntries(Object.entries({...customerOverviewMenu,...financeReportPages}).map(([key,entry])=>[key,entry.label])),
};
export const workspaceMenuLabelGroups=adminWorkspaceFocusGroups.map(({id,label,pages})=>({id,label,items:pages.map(key=>[key,defaultItems[key]])}));

function currentMenuLabels(state={}){
 const identity=state.workspaceMenuLabels;
 return identity?.company===Number(state.company)&&identity?.context===(state.contextRevision||0)?identity:null;
}

export const workspaceMenuDefaultLabel=(key,fallback='')=>fallback||defaultItems[key]||'';
export function workspaceMenuLabelText(state,key,fallback=''){
 const labels=currentMenuLabels(state)?.labels;
 return labels&&Object.hasOwn(labels,key)?labels[key]:customerWorkspaceDefaultLabel(state,key,workspaceMenuDefaultLabel(key,fallback));
}
export function workspaceMenuLabelMarkup(state,key,defaultLabel){
 const labels=currentMenuLabels(state)?.labels;
 defaultLabel=customerWorkspaceDefaultLabel(state,key,workspaceMenuDefaultLabel(key,defaultLabel));
 const label=labels&&Object.hasOwn(labels,key)?labels[key]:defaultLabel;
 return `<span ${labels&&Object.hasOwn(labels,key)?'data-workspace-menu-custom':''} data-workspace-menu-label="${esc(key)}" data-workspace-menu-default="${esc(defaultLabel)}" title="${esc(label)}">${esc(label)}</span>`;
}

/** Update captions in place. An open menu, keyboard focus and the active table keep their original DOM. */
export function applyWorkspaceMenuLabels(state,root,company){
 if(!company||Number(company.id)!==Number(state.company)||!Number(state.company))return false;
 const revision=company.menuLabels?.version??0;
 if(!Number.isSafeInteger(revision)||revision<0||revision<=(currentMenuLabels(state)?.revision??-1))return false;
 const labels=Object.fromEntries(Object.entries(company.menuLabels?.labels??{}).filter(([,label])=>typeof label==='string'&&label.trim()));
 state.workspaceMenuLabels={company:Number(company.id),context:state.contextRevision||0,revision,labels};
 for(const caption of root?.querySelectorAll('[data-workspace-menu-label]')??[]){
  if(caption.closest?.('[data-menu-label-snapshot]'))continue;
  const {workspaceMenuLabel:key,workspaceMenuDefault:defaultLabel}=caption.dataset;if(!key)continue;
  const label=Object.hasOwn(labels,key)?labels[key]:defaultLabel;
  if(caption.textContent!==label)caption.textContent=label;caption.title=label;caption.toggleAttribute('data-workspace-menu-custom',Object.hasOwn(labels,key));
 }
 const documentRoot=root?.ownerDocument||(root?.nodeType===9?root:globalThis.document);documentRoot?.dispatchEvent?.(new CustomEvent('omnigo-menu-labels-changed',{detail:{company:Number(company.id)}}));
 return true;
}

export function workspaceMenuLabelFields(labels){
 const value=key=>Object.hasOwn(labels,key)?labels[key]:'';
 return workspaceMenuLabelGroups.map(group=>{
  const label=t(group.label),key='group.'+group.id;
  const heading={key,label:t('Grup başlığı'),placeholder:label,value:value(key)};
  const items=group.items.map(([key,name])=>{
   const caption=t(name);
   return {key,label:caption,placeholder:caption,value:value(key)};
  });
  return {label,fields:[heading,...items]};
 });
}
