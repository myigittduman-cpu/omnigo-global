import {t as i18nT,text as i18nText,html as i18nHtml,currentLocale} from './i18n.js';
import {menuDescriptions} from './menu-descriptions.js';
import {esc,icon} from './ui.js';
import {orderedNavigation} from './navigation-order.js';
import {customerPageNames} from './customer-modules.js';
import {mountWorkspaceSiteLink} from './workspace-site-link.js';
import {adminWorkspaceFocusGroups,workspaceFocusGroups} from './workspace-focus-model.js';
import {bindMobileWorkspaceMenu,mobileMenuQuery} from './workspace-mobile-menu.js';
import {customerOverviewKeys,customerOverviewMenu} from './customer-overview-menu.js';
import {isShipmentDocumentPage} from './shipment-document-pages.js';
import {financeReportKeys,financeReportPages,isFinanceReportPage} from './finance-report-pages.js';
import {workspaceMenuDefaultLabel,workspaceMenuLabelMarkup} from './workspace-menu-labels.js';
import {workspaceMenuLayout} from './workspace-menu-layout.js';

import {marketplaceStockGroupPages} from './marketplace-stock-pilot.js';
import {isCustomPageView,customPageGroup,customPageForView} from './custom-page-navigation.js';

const adminPrimary=['overview','customers','channels','orders','deliveries','cityProducts','shipments'];
const adminSecondary=['inventory','marketplaceStocks','analytics','connections','payments','shipmentInvoices','tasks','reports','support','returns','inbox','fileCenter','customPages',...financeReportKeys];
export const clientPages=Object.freeze(['inventory','overview',...customerOverviewKeys,'orders','returns','channels','analytics','tasks','payments','deliveries','cityProducts','shipments','shipmentInvoices','reports','support','customPages']);
const sharedCaptions=new Set(['overview','returns','shipments','analytics','tasks','payments','support']);
let navigationBinding;
const mountedNavigation=new WeakMap();

const pendingCount=value=>Number.isSafeInteger(value)&&value>0?value:0;
const questionCountLabel=count=>i18nText`${count.toLocaleString(currentLocale())} yanıt bekleyen soru`;
export function updateQuestionMenu(app,value){
 const badge=app.querySelector('.workspace-directory [data-question-count]');
 if(!badge)return;
 const count=pendingCount(value);
 const label=count?count.toLocaleString(currentLocale()):'';
 if(badge.textContent!==label)badge.textContent=label;
 badge.setAttribute('aria-label',questionCountLabel(count));
 badge.toggleAttribute('hidden',!count);
}
export function updateRequestMenu(app,value){
 const button=app.querySelector('.workspace-directory [data-nav="returns"]');
 const badge=button?.querySelector('[data-request-count]');
 if(!badge)return;
 const count=pendingCount(value);
 button.dataset.requestPending=String(Boolean(count));
 badge.textContent=count||'';
 badge.setAttribute('aria-label',i18nText`${count} bekleyen talep`);
 badge.toggleAttribute('hidden',!count);
}

/** UI allowlists are separate from, and do not replace, server authorization. */
export function workspaceNavigation(names,state,hasCompany=false){
 const admin=state.user?.role==='admin',client=state.user?.role==='client';
 if(!admin&&!client)return '';
 const accountingMenu=client&&state.data?.workspaceAccess?.storeRole==='accounting_logistics';
 const allowed=admin?[...adminPrimary,...adminSecondary]:clientPages;
 const entries=Object.entries(orderedNavigation(customerPageNames(names,state),state.user)).filter(([id])=>(allowed.includes(id)||isCustomPageView(id))&&(!state.demo||id!=='fileCenter'));
 const visiblePages=new Map(entries);
 const button=([id,name],slot,centered)=>{
  const pageIcon=customerOverviewMenu[id]?.icon||financeReportPages[id]?.icon||(['cityProducts','marketplaceStocks'].includes(id)?'inventory':['fileCenter','customPages'].includes(id)||isCustomPageView(id)?'reports':id==='customers'?'store':id);
  const label=sharedCaptions.has(id)?i18nT(workspaceMenuDefaultLabel(id)):name;
  const count=id==='returns'?pendingCount(state.data?.marketplaceRequestsPending):0;
  const questions=id==='inbox'?pendingCount(state.data?.marketplaceQuestionsPending):0;
  const badge=id==='inbox'
   ?i18nHtml`<span data-question-count ${questions?'':'hidden'} aria-label="${esc(questionCountLabel(questions))}">${questions?questions.toLocaleString(currentLocale()):''}</span>`
   :id==='returns'?i18nHtml`<span data-request-count ${count?'':'hidden'} aria-label="${esc(i18nText`${count} bekleyen talep`)}">${count||''}</span>`:'';
  return i18nHtml`<button type="button" data-nav="${id}" data-menu-row="${slot.row}" data-menu-row-span="${slot.rowSpan}" data-menu-column="${slot.column}" data-menu-span="${slot.span}" ${centered?'data-menu-centered':''} class="workspace-menu-link${state.view===id?' active':''}" ${state.view===id?'aria-current="page"':''} ${count?'data-request-pending="true"':''}><span class="workspace-menu-icon">${icon(pageIcon)}</span><span class="menu-copy">${workspaceMenuLabelMarkup(state,id,label)}<small>${esc(i18nT(customPageForView(state,id)?.summary||menuDescriptions[id]||''))}</small></span>${badge}</button>`;
 };
 const groups=(admin?adminWorkspaceFocusGroups:workspaceFocusGroups).map(group=>{
  const customIds=entries.filter(([id])=>customPageGroup(id)===group.id).map(([id])=>id);
  const groupEntries=[...marketplaceStockGroupPages(group,state),...customIds].filter(id=>visiblePages.has(id)).map(id=>[id,visiblePages.get(id)]);
  if(!groupEntries.length)return '';
  const layout=workspaceMenuLayout(groupEntries.length);
  const financeTrio=group.id==='finance'&&groupEntries.map(([id])=>id).join(',')==='financeProfitability,analytics,payments';
  const links=groupEntries.map((entry,index)=>{
   const slot=financeTrio&&entry[0]==='payments'?{...layout.slots[index],column:1,span:layout.units}:layout.slots[index];
   return button(entry,slot,groupEntries.length===1||groupEntries.length===3&&index===2);
  });
  const shipmentIndex=groupEntries.findIndex(([id])=>id==='shipments');
  const adjacentProducts=shipmentIndex>=0&&groupEntries[shipmentIndex+1]?.[0]==='cityProducts';
  const menuLinks=adjacentProducts
   ?links.slice(0,shipmentIndex).join('')+'<div class="workspace-menu-pair">'+links.slice(shipmentIndex,shipmentIndex+2).join('')+'</div>'+links.slice(shipmentIndex+2).join('')
   :links.join('');
  const label=icon(group.icon)+workspaceMenuLabelMarkup(state,'group.'+group.id,i18nT(group.label));
  return i18nHtml`<section class="workspace-directory-group" data-menu-group="${group.id}" data-menu-columns="${layout.columns}" aria-labelledby="workspace-menu-${group.id}">
   <h2 id="workspace-menu-${group.id}"><span class="workspace-directory-heading-desktop">${label}</span><button type="button" class="workspace-directory-toggle" data-mobile-menu-toggle="${group.id}" aria-expanded="false" aria-controls="workspace-menu-panel-${group.id}">${label}<svg class="workspace-directory-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg></button></h2>
   <div class="workspace-directory-panel" id="workspace-menu-panel-${group.id}">
    <div class="workspace-directory-scroll"><div class="workspace-directory-links" data-menu-layout="two-row" data-menu-count="${links.length}">${menuLinks}</div></div>
   </div>
  </section>`;
 }).join('');
 return i18nHtml`<nav class="workspace-top-navigation workspace-directory${accountingMenu?' workspace-directory--accounting-logistics':''}" data-menu-weighted id="workspace-directory" aria-label="Ana menü">${hasCompany?'<div class="workspace-menu-customer"></div>':''}<div class="workspace-directory-grid">${groups}</div></nav>`;
}

function directorySignature(root){
 const groups=[...root.querySelectorAll('.workspace-directory-group')];
 return JSON.stringify([root.querySelector('.workspace-directory')?.className,Boolean(root.querySelector('.workspace-menu-customer')),groups.map(group=>[
  group.dataset.menuGroup,group.querySelector('h2').textContent,
  [...group.querySelectorAll('.workspace-menu-link')].map(button=>[button.dataset.nav||'',button.querySelector('.menu-copy')?.textContent.trim()])
 ])]);
}

/** Navigation continues through the app's role, tenant and unsaved-change guards. */
export function mountWorkspaceNavigation(app,names,state,{reuseStructure=false}={}){
 const existing=mountedNavigation.get(app);
 if(reuseStructure&&existing?.holder.isConnected){existing.refresh(false);return existing.refresh;}
 navigationBinding?.abort();navigationBinding=new AbortController();
 if(!['admin','client'].includes(state.user?.role))return ()=>{};
 app.querySelector('.sidebar')?.remove();
 const workspace=app.querySelector('.workspace');
 workspace.classList.add('workspace-top-layout',`workspace-top-layout--${state.user.role}`);
 let header=workspace.querySelector('.workspace-navigation-header');
 const topbar=workspace.querySelector('.topbar');
 if(!header){header=document.createElement('div');header.className='workspace-navigation-header';topbar.before(header);header.append(topbar);}
 header.classList.add('workspace-directory-header');
 let holder=header.querySelector('.workspace-navigation-slot');
 if(!holder){holder=document.createElement('div');holder.className='workspace-navigation-slot';header.append(holder);}
 holder.classList.add('workspace-directory-slot');
 const company=header.querySelector('.workspace-company');
 const anchor=[...topbar.childNodes].find(node=>node.nodeType===8&&node.textContent==='selected-customer')||document.createComment('selected-customer');
 if(!anchor.parentNode)topbar.insertBefore(anchor,topbar.querySelector('.top-actions'));
 const mobile=window.matchMedia(mobileMenuQuery);
 const syncMobileMenu=bindMobileWorkspaceMenu(holder,navigationBinding.signal,mobile);
 const placeCompany=()=>{if(!company)return;const slot=holder.querySelector('.workspace-menu-customer');if(!mobile.matches&&slot){if(company.parentNode!==slot)slot.append(company);}else if(anchor.nextSibling!==company)anchor.after(company);};
 mobile.addEventListener('change',placeCompany,{signal:navigationBinding.signal});
 const refresh=(checkStructure=true)=>{
  if(checkStructure){
   const markup=workspaceNavigation(names,state,Boolean(company)),template=document.createElement('template');template.innerHTML=markup;
   if(!holder.querySelector('.workspace-directory')||directorySignature(holder)!==directorySignature(template.content))holder.innerHTML=markup;
  }
  for(const button of holder.querySelectorAll('[data-nav]')){
   const selected=button.dataset.nav===state.view;
   button.classList.toggle('active',Boolean(selected));if(selected)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');
  }
  updateRequestMenu(holder,state.data?.marketplaceRequestsPending);
  updateQuestionMenu(holder,state.data?.marketplaceQuestionsPending);
  if(holder.dataset.navigationView!==state.view&&(isShipmentDocumentPage(state.view)||isFinanceReportPage(state.view)))syncMobileMenu('',{instant:true});
  else syncMobileMenu();
  holder.dataset.navigationView=state.view;
  placeCompany();
 };
 mountedNavigation.set(app,{holder,refresh});
 refresh();mountWorkspaceSiteLink(topbar);return refresh;
}
