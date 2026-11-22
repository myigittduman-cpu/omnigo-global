import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,icon} from './ui.js';
import {orderedNavigation} from './navigation-order.js';

const primaryPages=['overview','customers','channels','orders','deliveries','shipments'];

export function adminNavigation(names,state){
 const entries=Object.entries(orderedNavigation(names,state.user)).filter(([id])=>id!=='inbox');
 const button=([id,name])=>i18nHtml`<button type="button" data-nav="${id}" class="${state.view===id?'active':''}" ${state.view===id?'aria-current="page"':''}>${icon(id)}<span>${esc(name)}</span></button>`;
 const primary=entries.filter(([id])=>primaryPages.includes(id));
 const secondary=entries.filter(([id])=>!primaryPages.includes(id));
 const secondaryActive=secondary.some(([id])=>state.view===id);
 return i18nHtml`<nav class="admin-top-navigation" aria-label="Ana menü"><div class="admin-primary-links">${primary.map(button).join('')}<details class="admin-more"><summary class="${secondaryActive?'active':''}">${icon('more')}<span>Diğer</span><span class="admin-more-chevron" aria-hidden="true">⌄</span></summary><div class="admin-more-panel"><span class="admin-menu-label">Diğer bölümler</span>${secondary.map(button).join('')}</div></details></div><button type="button" class="admin-menu-edit" data-edit-navigation aria-label="Menü sırasını düzenle">Düzenle</button></nav>`;
}

/** Only the admin shell changes; existing navigation and form guards keep ownership. */
export function mountAdminNavigation(app,names,state){
 if(state.user.role!=='admin')return ()=>{};
 app.querySelector('.sidebar')?.remove();
 const workspace=app.querySelector('.workspace');
 workspace.classList.add('admin-top-layout');
 const header=document.createElement('div');header.className='admin-navigation-header';
 const topbar=workspace.querySelector('.topbar');topbar.before(header);header.append(topbar);
 const holder=document.createElement('div');holder.className='admin-navigation-slot';
 header.append(holder);
 const refresh=()=>{
  holder.innerHTML=adminNavigation(names,state);
  const details=holder.querySelector('details'),summary=details.querySelector('summary');
  summary.setAttribute('aria-expanded','false');
  details.addEventListener('toggle',()=>summary.setAttribute('aria-expanded',String(details.open)));
  details.addEventListener('keydown',event=>{if(event.key==='Escape'){details.open=false;summary.focus();event.stopPropagation();}});
  details.addEventListener('focusout',event=>{if(event.relatedTarget&&!details.contains(event.relatedTarget))details.open=false;});
 };
 workspace.addEventListener('pointerdown',event=>{const details=holder.querySelector('details');if(details&&!details.contains(event.target))details.open=false;});
 refresh();return refresh;
}
