import {t} from './i18n.js';
import {esc,icon} from './ui.js';
import {navigationRowSizes} from './workspace-navigation-balance.js';
import {workspaceFocusCategory,workspaceFocusModel} from './workspace-focus-model.js';

const links=grid=>[...grid.children].filter(node=>node.matches('[data-nav],[data-hidden-menus]'));
const pageOf=button=>button.hasAttribute('data-hidden-menus')?'hidden-menus':button.dataset.nav;

/** Reuse the already-authorized buttons; category switches never navigate or save. */
export function bindWorkspaceFocusNavigation(nav,{signal,onLayout=()=>{}}={}){
 if(!nav)return ()=>{};
 nav.classList.add('workspace-focus-navigation');
 let grid=nav.querySelector('.workspace-navigation-grid,.workspace-focus-menu-grid');
 if(!grid){
  grid=document.createElement('div');grid.className='workspace-focus-menu-grid';
  grid.append(...[...nav.children].filter(node=>node.matches('[data-nav],[data-hidden-menus]')));nav.append(grid);
 }
 let heading=nav.querySelector('.workspace-focus-heading');
 if(!heading){heading=document.createElement('div');heading.className='workspace-focus-heading';grid.before(heading);}
 let categories=nav.querySelector('.workspace-focus-categories');
 if(!categories){categories=document.createElement('div');categories.className='workspace-focus-categories';categories.setAttribute('role','group');categories.setAttribute('aria-label',t('Çalışma alanı kategorileri'));heading.append(categories);}
 const company=nav.querySelector('.workspace-menu-customer');if(company)heading.append(company);
 const selectedView=()=>links(grid).find(button=>button.classList.contains('active'));
 const updateRows=()=>{
  let tabOffset=0;for(const count of navigationRowSizes(categories.children.length,nav.clientWidth<620?2:4))for(let column=0;column<count;column++)categories.children[tabOffset++].style.setProperty('--workspace-focus-tab-row-size',String(count));
  if(grid.classList.contains('workspace-focus-menu-grid')){
   const visible=links(grid).filter(button=>!button.hidden),maxColumns=grid.clientWidth>=640?4:2;
   let offset=0;for(const count of navigationRowSizes(visible.length,maxColumns))for(let column=0;column<count;column++)visible[offset++].style.setProperty('--workspace-focus-row-size',String(count));
  }
  onLayout();
 };
 function showCategory(id){
  nav.dataset.workspaceFocusCategory=id;
  for(const button of categories.children){const selected=button.dataset.workspaceFocusTab===id;button.setAttribute('aria-pressed',String(selected));}
  for(const button of links(grid)){
   const group=workspaceFocusCategory(pageOf(button));button.dataset.workspaceFocusGroup=group;button.hidden=group!==id;
  }
  updateRows();
 }
 nav.addEventListener('click',event=>{
  const button=event.target.closest('[data-workspace-focus-tab]');if(!button||!categories.contains(button))return;
  showCategory(button.dataset.workspaceFocusTab);
 },{signal});
 window.addEventListener('resize',updateRows,{signal});
 return view=>{
  const buttons=links(grid),active=selectedView();
  const activeView=active?.hasAttribute('data-hidden-menus')?'hidden-menus':view;
  const changed=nav.dataset.workspaceFocusView!==view;
  const model=workspaceFocusModel(buttons.map(pageOf),{view:activeView,selectedCategory:changed?undefined:nav.dataset.workspaceFocusCategory});
  const signature=model.groups.map(group=>[group.id,t(group.label)].join(':')).join('|');
  if(categories.dataset.signature!==signature){
   categories.innerHTML=model.groups.map(group=>`<button type="button" class="workspace-focus-tab" data-workspace-focus-tab="${group.id}" aria-pressed="false"><span class="workspace-focus-tab-icon">${icon(group.icon)}</span><span>${esc(t(group.label))}</span></button>`).join('');categories.dataset.signature=signature;
  }
  categories.hidden=!model.groups.length;
  grid.hidden=!buttons.length;nav.hidden=!buttons.length&&!company?.childElementCount;
  nav.dataset.workspaceFocusView=view;
  showCategory(model.selected);
 };
}
