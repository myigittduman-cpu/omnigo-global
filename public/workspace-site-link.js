import {t} from './i18n.js';
import {mobileMenuQuery} from './workspace-mobile-menu.js';

function mountMenuDiagonal(topbar,title){
 const header=topbar.closest('.workspace-directory-header');
 const notification=topbar.querySelector('.notification-center>.notification-bell');
 if(!header||!notification)return;
 const slot=header.querySelector('.workspace-directory-slot');
 const wide=matchMedia('(min-width:1280px)');
 const mobile=matchMedia(mobileMenuQuery);
 const initial=title.ownerDocument.createRange();
 initial.setStart(title.firstChild,0);
 initial.setEnd(title.firstChild,1);
 const measure=()=>{
  if(!header.isConnected){
   observer.disconnect();menuObserver.disconnect();
   for(const mode of [wide,mobile])mode.removeEventListener('change',measure);
   return;
  }
  const menu=header.querySelector('#workspace-directory');
  if(!menu||mobile.matches){
   header.style.removeProperty('--menu-operation-extra');
   delete header.dataset.menuDiagonal;
   delete header.dataset.menuRules;
   return;
  }
  const operations=menu.querySelector('[data-menu-group="operations"]');
  if(wide.matches){
   const frame=menu.getBoundingClientRect(),button=notification.getBoundingClientRect();
   const firstLetter=initial.getBoundingClientRect(),rail=topbar.getBoundingClientRect();
   if(!button.width||!firstLetter.width)return;
   // Keep the edge to the left of the title for its entire height.
   const descent=firstLetter.bottom-button.top;
   const part=Math.min(1,Math.max(.1,(title.getBoundingClientRect().top-button.top)/descent));
   const point=button.left+(firstLetter.left-6-button.left)/part;
   header.style.setProperty('--menu-rail-width',rail.width+'px');
   header.style.setProperty('--menu-edge-top-x',(button.left-frame.left)+'px');
   header.style.setProperty('--menu-edge-top-y',(button.top-frame.top)+'px');
   header.style.setProperty('--menu-edge-bottom-x',(point-frame.left)+'px');
   header.style.setProperty('--menu-edge-bottom-y',(firstLetter.bottom-frame.top)+'px');
   header.dataset.menuDiagonal='';
   if(operations){
    // Use the empty part of the diagonal without clipping the menu.
    const depth=Math.min(1,Math.max(0,(operations.getBoundingClientRect().bottom-button.top)/descent));
    const edge=button.left+(point-button.left)*depth;
    header.style.setProperty('--menu-operation-extra',Math.max(0,edge-rail.left-10)+'px');
   }
  }else{
   header.style.removeProperty('--menu-operation-extra');
   delete header.dataset.menuDiagonal;
  }
  if(operations){
   const grid=menu.querySelector('.workspace-directory-grid');
   const scroll=operations.querySelector('.workspace-directory-scroll');
   const last=operations.querySelector('.workspace-directory-links').lastElementChild;
   const lane=scroll.getBoundingClientRect();
   const end=Math.min(lane.right,last.getBoundingClientRect().right+scroll.scrollLeft);
   header.style.setProperty('--menu-rule-width',(end-grid.getBoundingClientRect().left)+'px');
   header.dataset.menuRules='';
  }else{
   header.style.removeProperty('--menu-operation-extra');
   delete header.dataset.menuRules;
  }
 };
 const observer=new ResizeObserver(measure);
 const menuObserver=new MutationObserver(measure);
 if(slot)menuObserver.observe(slot,{childList:true,subtree:true});
 for(const element of [header,topbar,title,notification])observer.observe(element);
 for(const mode of [wide,mobile])mode.addEventListener('change',measure);
 measure();
 document.fonts?.ready.then(measure);
}

function mountWorkspaceCompanySignature(topbar){
 if(!topbar||topbar.querySelector('.workspace-company-signature'))return;
 const brand=document.createElement('div');brand.className='workspace-header-brand';
 const logo=topbar.querySelector('.mobile-brand'),crumb=topbar.querySelector('.crumb');let heading,reference;
 topbar.prepend(brand);if(logo)brand.append(logo);
 if(crumb){
  // Reserve the overview breadcrumb width so page names cannot move the signature.
  heading=document.createElement('div');heading.className='workspace-header-crumb';
  reference=crumb.cloneNode(true);reference.classList.add('workspace-header-reference');reference.setAttribute('aria-hidden','true');
  const page=reference.querySelector('strong');if(page)page.textContent=t('Genel bakış');
  crumb.title=crumb.textContent.trim();heading.append(reference,crumb);brand.append(heading);
 }
 const signature=document.createElement('div');signature.className='workspace-company-signature';
 const title=document.createElement('a');title.className='workspace-company-title';title.textContent='Omnigo Global E-Ticaret Operasyon ve Danışmanlık Ltd. Şti.';
 title.href='https://www.omnigoglobal.com/';
 title.target='_blank';
 title.rel='noopener noreferrer';
 signature.append(title);
 brand.after(signature);
 mountMenuDiagonal(topbar,title);
 if(heading&&reference){
  const fitHeading=()=>{
   if(!topbar.isConnected||!heading.getBoundingClientRect().width)return;
   const boundary=title.getBoundingClientRect().left-18;
   const available=Math.max(0,Math.floor(boundary-heading.getBoundingClientRect().left));
   heading.style.setProperty('--workspace-crumb-width',available+'px');
   const base=parseFloat(getComputedStyle(reference).fontSize);crumb.style.fontSize=base+'px';crumb.style.whiteSpace='nowrap';
   if(crumb.scrollWidth>available){crumb.style.fontSize=Math.max(11,base*available/crumb.scrollWidth)+'px';if(crumb.scrollWidth>available+1)crumb.style.whiteSpace='normal';}
  };
  topbar.addEventListener('omnigo-heading-changed',fitHeading);
  const observer=new ResizeObserver(()=>{if(!topbar.isConnected){observer.disconnect();return;}fitHeading();});observer.observe(topbar);observer.observe(title);fitHeading();document.fonts?.ready.then(fitHeading);
 }
}

// Keep the existing export for portal pages with a cached navigation module.
export {mountWorkspaceCompanySignature as mountWorkspaceSiteLink};
