const frames=new WeakMap();
export function workspaceFrameKey(state,company,names,language){
 return JSON.stringify([state.user?.id,state.user?.role,state.user?.name,state.user?.operatorName,state.user?.avatarVersion,state.company,state.contextRevision,company?.name,names,language]);
}
function syncSelectedNavigation(current,view){
 let hasActive=false;
 for(const button of current.querySelectorAll('[data-nav]')){
  const selected=button.dataset.nav===view;
  hasActive||=selected;
  button.classList.toggle('active',selected);
  if(selected)button.setAttribute('aria-current','page');
  else button.removeAttribute('aria-current');
 }
 current.querySelector('[data-more-menu]')?.classList.toggle('active',!hasActive);
}
/** Retain the shell only inside the same authenticated workspace and permission scope. */
export function renderWorkspaceFrame(app,markup,key){
 const template=app.ownerDocument.createElement('template');
 // Accept the original markup call during mixed-version deployments.
 if(typeof markup==='string'){
  template.innerHTML=markup;
  const next=template.content.querySelector('.workspace'),main=next?.querySelector('.main');
  if(!main){app.replaceChildren(template.content);frames.set(app,key);return false;}
  const full=markup,selected=template.content.querySelector('[data-nav][aria-current="page"]');
  markup={full:()=>full,main:main.outerHTML,crumb:next.querySelector('.crumb')?.innerHTML,
   view:selected?.dataset.nav,polished:next.classList.contains('workspace-polished')};
 }
 const current=app.querySelector('.workspace'),main=current?.querySelector('.main');
 if(!main||frames.get(app)!==key){template.innerHTML=markup.full();app.replaceChildren(template.content);frames.set(app,key);return false;}
 template.innerHTML=markup.main;
 main.replaceWith(template.content);
 current.classList.toggle('workspace-polished',markup.polished??markup.view!=='shipments');
 const crumb=current.querySelector('.crumb:not(.workspace-header-reference)');
 if(crumb&&markup.crumb!==undefined){crumb.innerHTML=markup.crumb;crumb.title=crumb.textContent.trim();current.querySelector('.topbar')?.dispatchEvent(new Event('omnigo-heading-changed'));}
 const mobile=app.querySelector('.mobile-nav');
 if(mobile&&markup.view)syncSelectedNavigation(mobile,markup.view);
 return true;
}
