import {t} from './i18n.js';

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
