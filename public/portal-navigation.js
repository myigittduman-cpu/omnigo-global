import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {menuDescriptions} from './menu-descriptions.js';
import {icon} from './ui.js';
import {orderedNavigation} from './navigation-order.js';
import {customerPageNames} from './customer-modules.js';
import {salesHomePage} from './sales-navigation.js';
import {workspaceMenuLabelMarkup} from './workspace-menu-labels.js';
export function portalNavigation(names,state,{mobile=false}={}){
 const visibleNames=customerPageNames(names,state);
 const admin=state.user.role==='admin',primary=admin?['overview','customers','orders']:[salesHomePage(state),visibleNames.orders?'orders':'analytics','payments'];
 const entries=Object.entries(orderedNavigation(visibleNames,state.user)).filter(([id])=>id!=='inbox'&&(!mobile||primary.includes(id)));
 const buttons=entries.map(([id,name])=>i18nHtml`<button data-nav="${id}" class="${state.view===id?'active':''}" ${state.view===id?'aria-current="page"':''}>${icon(id==='customers'?'store':id==='marketplaceStocks'?'inventory':id)}<span>${workspaceMenuLabelMarkup(state,id,i18nT(name))}${mobile?'':i18nHtml`<small class="menu-description">${i18nT(menuDescriptions[id])||''}</small>`}</span></button>`).join('');
 return buttons+(mobile?i18nHtml`<button type="button" data-more-menu class="${!primary.includes(state.view)?'active':''}" aria-controls="workspace-directory">${icon('more')}<span>Ana menü</span></button>`:'');
}
// Keep the existing shell binding; the mobile shortcut points to the inline menu.
export function openMoreMenu(){
 const menu=document.getElementById('workspace-directory');
 if(!menu)return;
 menu.scrollIntoView({block:'start',behavior:'instant'});
 const mobileToggle=menu.querySelector('.workspace-directory-group:has([aria-current="page"]) [data-mobile-menu-toggle]')||menu.querySelector('[data-mobile-menu-toggle]');
 (mobileToggle?.getClientRects().length?mobileToggle:menu.querySelector('.workspace-menu-link'))?.focus({preventScroll:true});
}
