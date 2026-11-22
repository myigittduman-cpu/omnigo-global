import {companyLogoSource} from './company-export-brand.js';
import {companyIdentity} from './workspace-entry.js';
import {userPortraitMarkup} from './user-portrait.js';
import {applyWorkspaceMenuLabels} from './workspace-menu-labels.js';

export function applyCustomerBranding(state,root,value){
 if(!value||Number(value.id)!==Number(state.company)||typeof value.name!=='string')return false;
 applyWorkspaceMenuLabels(state,root,value);
 const company=state.companies.find(item=>Number(item.id)===Number(value.id));if(!company)return false;
 const next={name:value.name,hasLogo:Boolean(value.hasLogo),brandingVersion:Number(value.brandingVersion)||0};
 if(Object.entries(next).every(([key,val])=>company[key]===val))return false;
 // Ignore a response issued before a newer branding revision was already displayed.
 if(next.brandingVersion<(Number(company.brandingVersion)||0))return false;
 Object.assign(company,next);
 if(state.user?.role==='client'&&Number(state.user.companyId)===Number(company.id)){
  state.user.avatarVersion=company.hasLogo?company.brandingVersion:null;
  for(const element of root.querySelectorAll('.account .avatar'))element.innerHTML=userPortraitMarkup(state.user,{companyName:company.name,fallbackName:company.name});
 }
 for(const element of root.querySelectorAll('[data-company-name]'))if(Number(element.dataset.companyName)===Number(company.id))element.textContent=company.name;
 for(const element of root.querySelectorAll('[data-company-logo]'))if(Number(element.dataset.companyLogo)===Number(company.id)){
  if(element.classList?.contains('upcoming-payments-company-logo')){element.hidden=!company.hasLogo;const image=element.querySelector('img');if(image&&company.hasLogo){image.src=companyLogoSource(company,{payment:true})+'&v='+company.brandingVersion;image.alt=company.name+' logosu';}else element.hidden=true;}
  else element.innerHTML=companyIdentity(company);
 }
 return true;
}
