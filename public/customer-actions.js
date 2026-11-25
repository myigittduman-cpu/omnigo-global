import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {customerMarketplaces} from './customer-marketplaces.js';
import {customerManagement} from './customer-management.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {customerPermissions} from './customer-permissions.js';
import {newCustomerSetup} from './customer-setup-new.js';

export function bindCustomerActions(root,state,{onSave,onSwitch,onAccountUpdated,onOpenStockList}){
 const openSelected=action=>()=>{
  const companyId=Number(state.company);
  if(state.user?.role!=='admin'||!state.companies?.some(company=>Number(company.id)===companyId))return;
  return action(companyId);
 };
 root.querySelector('#customerSetup')?.addEventListener('click',async event=>{
  const button=event.currentTarget;
  if(state.user?.role!=='admin'||button.disabled)return;
  button.disabled=true;
  try{await newCustomerSetup(state,{onSave,onAccountUpdated});}finally{button.disabled=false;}
 });
 root.querySelector('#customerMarketplaces')?.addEventListener('click',openSelected(companyId=>customerMarketplaces(state,onSave,companyId)));
 root.querySelector('#customerPermissions')?.addEventListener('click',openSelected(companyId=>customerPermissions(state,onSave,{companyId,onAccountUpdated,...(onOpenStockList?{onOpenStockList}:{})})));
 root.querySelector('#manageCustomer')?.addEventListener('click',openSelected(companyId=>customerManagement(state,onSave,'profile',companyId)));
 root.querySelector('[data-workspace-switch]')?.addEventListener('click',onSwitch);
 root.querySelector('#audit')?.addEventListener('click',async()=>{
  const token=workspaceToken(state);
  try{const rows=await api('/audit?company='+token.company);if(!sameWorkspace(state,token))return;
   openDialog(i18nT('İşlem geçmişi'),i18nHtml`<ul class="audit-list">${rows.map(row=>i18nHtml`<li><strong>${esc(row.action)}${row.record_id!=null?i18nText` · Kayıt ${Number(row.record_id)}`:''}</strong><span>${esc(row.name)} · ${esc(new Date(row.created).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}))}</span></li>`).join('')||i18nHtml('<li>Henüz işlem yok.</li>')}</ul>`);
  }catch(error){toast(error.message);}
 });
}
