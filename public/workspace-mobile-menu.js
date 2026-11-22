export const mobileMenuQuery='(max-width:760px), (max-width:1024px) and (max-height:540px) and (orientation:landscape), (max-width:1024px) and (pointer:coarse)';

export function bindMobileWorkspaceMenu(holder,signal,media=window.matchMedia(mobileMenuQuery)){
 const apply=(selected=holder.dataset.mobileMenuOpen||'',{instant=false}={})=>{
  const groups=[...holder.querySelectorAll('[data-menu-group]')];
  const open=media.matches&&groups.some(group=>group.dataset.menuGroup===selected)?selected:'';
  holder.dataset.mobileMenuOpen=open;
  for(const group of groups){
   const expanded=Boolean(open)&&group.dataset.menuGroup===open;
   const toggle=group.querySelector('[data-mobile-menu-toggle]');
   const panel=group.querySelector('.workspace-directory-panel');
   // A newly navigated page must start below the already collapsed menu.
   if(expanded)panel.removeAttribute('data-close-instant');
   else if(instant&&media.matches)panel.setAttribute('data-close-instant','');
   toggle.setAttribute('aria-expanded',String(expanded));
   panel.dataset.open=String(expanded);
   panel.inert=media.matches&&!expanded;
  }
 };
 holder.addEventListener('click',event=>{
  if(!media.matches)return;
  const toggle=event.target.closest('[data-mobile-menu-toggle]');
  if(toggle){
   const group=toggle.dataset.mobileMenuToggle;
   apply(holder.dataset.mobileMenuOpen===group?'':group);
  }else if(event.target.closest('.workspace-directory-panel button'))apply('');
 },{signal});
 document.addEventListener('click',event=>{
  if(!media.matches||!holder.dataset.mobileMenuOpen)return;
  const target=event.target.closest?.('[data-mobile-menu-toggle],.workspace-directory-panel[data-open=true]');
  if(!target||!holder.contains(target))apply('');
 },{signal,capture:true});
 document.addEventListener('keydown',event=>{
  if(event.key!=='Escape'||!media.matches||!holder.dataset.mobileMenuOpen||document.querySelector('dialog[open]'))return;
  const toggle=holder.querySelector('[data-mobile-menu-toggle][aria-expanded=true]');
  apply('');
  if(holder.contains(document.activeElement)){event.preventDefault();toggle?.focus({preventScroll:true});}
 },{signal});
 media.addEventListener('change',()=>apply(''),{signal});
 return apply;
}
