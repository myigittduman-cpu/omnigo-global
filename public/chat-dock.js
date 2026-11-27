export function bindChatDock(root,trigger,button,ownerId,signal){
 const preference='omnigo.chat.dock.'+ownerId;
 let preferred='top',toolbar=null,mobileNav=null;
 const wide=matchMedia('(min-width:1280px)');
 const app=document.getElementById('app');
 let account=null,accountParent=null,accountNext=null;
 try{if(localStorage.getItem(preference)==='bottom')preferred='bottom';}catch{}

 function restoreAccount(){
  if(!account)return;
  if(accountParent.isConnected)accountParent.insertBefore(account,accountNext?.parentNode===accountParent?accountNext:null);
  else account.remove();
  account=accountParent=accountNext=null;
 }
 function alignAccount(){
  const actions=wide.matches?app?.querySelector('.workspace-directory-header .top-actions'):null;
  if(actions!==accountParent)restoreAccount();
  if(!actions||account)return;
  account=actions.querySelector(':scope > .account');
  if(!account)return;
  accountParent=actions;accountNext=account.nextSibling;
  root.insertBefore(account,trigger);
 }

 function measureMobileNav(){
  const height=mobileNav?.getBoundingClientRect().height||0;
  // The navigation's border box already includes the device safe area.
  if(height)root.style.setProperty('--chat-mobile-bottom',Math.ceil(height+8)+'px');
  else root.style.removeProperty('--chat-mobile-bottom');
 }
 const navResize=typeof ResizeObserver==='function'?new ResizeObserver(measureMobileNav):null;
 function align(){
  alignAccount();
  const nav=document.querySelector('.mobile-nav');
  if(nav!==mobileNav){
   navResize?.disconnect();mobileNav=nav;
   if(nav)navResize?.observe(nav,{box:'border-box'});
  }
  measureMobileNav();
  const bar=document.getElementById('omnigo-desktop-toolbar');
  if(toolbar!==bar)toolbar?.style.removeProperty('--chat-toolbar-space');
  toolbar=bar;
  const actions=bar?.querySelector('.desktop-bar-actions');
  const brand=bar?.querySelector('.desktop-bar-brand');
  const controls=bar?.querySelector('.desktop-window-controls');
  const frame=bar?.getBoundingClientRect(),windowButtons=controls?.getBoundingClientRect();
  const divider=windowButtons?.width?windowButtons.left:frame?.right??0;
  const gap=bar?parseFloat(getComputedStyle(bar).columnGap)||0:0;
  const size=trigger.getBoundingClientRect();
  const row=root.getBoundingClientRect();
  const reservedWidth=account?row.width:size.width;
  const minRight=(brand?.getBoundingClientRect().right??0)+(actions?.getBoundingClientRect().width??0)+reservedWidth+2*gap+12;
  const available=bar&&!bar.hidden&&actions&&brand&&!matchMedia('(hover:none) and (pointer:coarse)').matches
   &&divider-10>=Math.max(minRight,Math.min(360,innerWidth-32)+8);
  const top=available&&preferred==='top';
  root.dataset.dock=top?'top':'bottom';
  button.hidden=!available;
  button.textContent=preferred==='top'?'Sağ alta taşı':'Üste taşı';
  if(!top){bar?.style.removeProperty('--chat-toolbar-space');return;}

  const avatar=document.querySelector('.topbar .top-actions .account .avatar')?.getBoundingClientRect();
  const preferredRight=avatar?.width?avatar.left+(avatar.width+size.width)/2:divider-10;
  const right=Math.min(divider-10,Math.max(minRight,divider-size.width-24,preferredRight));
  root.style.setProperty('--chat-right',innerWidth-right+'px');
  root.style.setProperty('--chat-top',Math.max(0,(frame.height-(account?row.height:size.height))/2)+'px');
  bar.style.setProperty('--chat-toolbar-space',Math.max(0,divider-right+reservedWidth+12-(windowButtons?.width?gap:0))+'px');
 }

 function toggle(){
  preferred=preferred==='top'?'bottom':'top';
  try{localStorage.setItem(preference,preferred);}catch{}
  align();
 }

 const observer=app?new MutationObserver(align):null;
 observer?.observe(app,{childList:true});
 window.addEventListener('resize',align,{signal,passive:true});
 window.addEventListener('omnigo-desktop-chrome-ready',align,{signal});
 return {align,toggle,dispose(){
  observer?.disconnect();
  navResize?.disconnect();
  restoreAccount();
  toolbar?.style.removeProperty('--chat-toolbar-space');
 }};
}
