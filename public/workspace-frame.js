const frames=new WeakMap();
export function workspaceFrameKey(state,company,names,language){
 return JSON.stringify([state.user?.id,state.user?.role,state.user?.name,state.user?.operatorName,state.user?.avatarVersion,state.company,state.contextRevision,company?.name,names,language]);
}
function syncSelectedNavigation(current,next){
 for(const button of current.querySelectorAll('[data-nav]')){
  const replacement=[...next.querySelectorAll('[data-nav]')].find(node=>node.dataset.nav===button.dataset.nav);
  if(!replacement)continue;
  button.classList.toggle('active',replacement.classList.contains('active'));
  if(replacement.hasAttribute('aria-current'))button.setAttribute('aria-current',replacement.getAttribute('aria-current'));
  else button.removeAttribute('aria-current');
 }
 const more=current.querySelector('[data-more-menu]'),replacement=next.querySelector('[data-more-menu]');
 if(more&&replacement)more.className=replacement.className;
}
/** Retain the shell only inside the same authenticated workspace and permission scope. */
export function renderWorkspaceFrame(app,markup,key){
 const template=document.createElement('template');template.innerHTML=markup;
 const current=app.querySelector('.workspace'),next=template.content.querySelector('.workspace');
 if(!current||!next||frames.get(app)!==key){app.replaceChildren(template.content);frames.set(app,key);return false;}
 const main=current.querySelector('.main'),nextMain=next.querySelector('.main');
 if(!main||!nextMain){app.replaceChildren(template.content);return false;}
 main.replaceWith(nextMain);
 current.classList.toggle('workspace-polished',next.classList.contains('workspace-polished'));
 const crumb=current.querySelector('.crumb:not(.workspace-header-reference)'),nextCrumb=next.querySelector('.crumb');
 if(crumb&&nextCrumb){crumb.innerHTML=nextCrumb.innerHTML;crumb.title=crumb.textContent.trim();current.querySelector('.topbar')?.dispatchEvent(new Event('omnigo-heading-changed'));}
 const mobile=app.querySelector('.mobile-nav'),nextMobile=template.content.querySelector('.mobile-nav');
 if(mobile&&nextMobile)syncSelectedNavigation(mobile,nextMobile);
 return true;
}
