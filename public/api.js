import {clearPageSnapshots} from './workspace-page-cache.js';
import {applyWorkspaceDesign} from './workspace-design-state.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {updateReviewNotice} from './store-review.js';
import {confirmMutation} from './action-confirmation.js';
import {reportSaveFeedback} from './save-feedback.js';
import {marketplaceAccessCompany} from './marketplace-access-policy.js';
import {managementGrant,clearManagementGrants} from './management-access-state.js';
let csrf='';
let operatorToken='';
export function setOperatorToken(value){if(operatorToken!==value){clearPageSnapshots();clearManagementGrants();}operatorToken=value;}
export function setCsrf(value){if(csrf!==value)clearPageSnapshots();if(!value||csrf&&csrf!==value)clearManagementGrants();csrf=value;}
function accessHeaders(path,method,data){const company=marketplaceAccessCompany(path,method,data),grant=company===null?'':managementGrant();return grant?{'X-Omnigo-Management-Grant':grant}:{};}
export async function upload(path,data,{signal}={}){
 const archive=/^\/file-center\/archive\?company=[1-9][0-9]*$/.test(path);
 if(!archive)await confirmMutation(path,'POST',data);
 const saveOwner=csrf,operatorOwner=operatorToken;
 const response=await fetch('/api'+path,{method:'POST',signal,credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'X-Omnigo-Operator':operatorToken,...accessHeaders(path,'POST')},body:data});
 const result=await response.json().catch(()=>null);
 if(!response.ok||!result){const error=new Error(i18nT(result?.error)||i18nT('Dosya yüklenemedi.'));error.status=response.status;throw error;}
 if(!archive){
  clearPageSnapshots();
  if(saveOwner===csrf&&operatorOwner===operatorToken)reportSaveFeedback(path,'POST',result,data);
 }
 return result;
}
export async function api(path,method='GET',data,{confirmed=false,signal,accessRetried=false}={}){
 if(!confirmed)await confirmMutation(path,method,data);
 const saveOwner=csrf,operatorOwner=operatorToken;
 const response=await fetch('/api'+path,{method,signal,cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Omnigo-Operator':operatorToken,...accessHeaders(path,method,data)},...(data?{body:JSON.stringify(data)}:{})});
 const result=await response.json();if(!response.ok){
  const company=marketplaceAccessCompany(path,method,data),current=()=>saveOwner===csrf&&operatorOwner===operatorToken&&!signal?.aborted;
  if(response.status===423&&company!==null&&!accessRetried&&current()){
   const {renewMarketplaceAccess}=await import('./marketplace-access.js');
   if(await renewMarketplaceAccess(company,current))return api(path,method,data,{confirmed:true,signal,accessRetried:true});
  }
  const error=new Error(i18nT(result.error)||i18nT('İşlem tamamlanamadı.'));error.status=response.status;throw error;
 }applyWorkspaceDesign(result?.workspaceDesign);updateReviewNotice(result);if(method!=='GET'&&!path.startsWith('/shipments/search')&&!path.startsWith('/push/')&&!path.startsWith('/chat/')&&!path.startsWith('/admin/marketplace-access'))clearPageSnapshots();if(saveOwner===csrf&&operatorOwner===operatorToken)reportSaveFeedback(path,method,result,data);return result;
}
