import {html,t} from './i18n.js';
import {esc} from './ui.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {requestCustomerSwitch} from './customer-switch.js';

export function managementCompanySelector(companies,selected='',hint='Firma bilgileri ve kullanıcılar seçtiğiniz mağazaya aittir. Değişiklikler Kaydet ve onaydan sonra uygulanır.'){
 return html`<label class="management-company-selector"><span>İşlem yapmak istediğiniz mağaza</span><select data-management-company><option value="">Mağaza seçin</option>${companies.map(company=>html`<option value="${Number(company.id)}" ${Number(company.id)===Number(selected)?'selected':''}>${esc(company.name)}</option>`).join('')}</select><small>${esc(t(hint))}</small></label>`;
}

/** The settings customer is local to this dialog, never the active workspace. */
export function bindManagementCompany(dialog,companies,companyId,{current,onSelect}){
 const select=dialog.querySelector('[data-management-company]');let switching=false;
 select.onchange=async()=>{
  const next=companies.find(company=>Number(company.id)===Number(select.value));
  select.value=companyId?String(companyId):'';
  if(switching||!next||Number(next.id)===Number(companyId)||!current())return;
  switching=true;select.disabled=true;
  try{
   const transition=requestCustomerSwitch(Number(next.id));if(transition!==null){await transition;return;}
   const source=companies.find(company=>Number(company.id)===Number(companyId))?.name||'';
   if(!await canLeaveDialogDrafts({source,target:next.name})||!current())return;
   // Replace the tenant's content in the same top-layer window; never expose the page below.
   await onSelect(Number(next.id));
  }finally{switching=false;if(select.isConnected)select.disabled=false;}
 };
}
