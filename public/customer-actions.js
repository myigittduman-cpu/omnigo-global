import {shippingScopeFields,bindShippingScope} from './shipping-scope.js';
import {bindInitialPermissions} from './customer-initial-permissions.js';
import {onboardingOptions,selectedOnboardingOptions} from './customer-onboarding-options.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,now,openDialog,toast} from './ui.js';
import {customerMarketplaces} from './customer-marketplaces.js';
import {setupProviders} from './customer-api-help.js';
import {customerSetup} from './customer-setup.js';
import {customerManagement} from './customer-management.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {customerPermissions} from './customer-permissions.js';

const shipmentLiveOptions=new Set(['shipments','deliveries','cityProducts','shipmentInvoices']);
const shipmentManualOptions=new Set(['shipmentEntry','operationCorrections','invoiceUpload','deliveryDocumentUpload']);

export async function createCompany(onCreated,current=()=>true){
 let template;try{template=await api('/management/permission-template');}catch(error){toast(error.message);return;}
 if(!current())return;
 const today=now(),first=today.slice(0,7)+'-01';
 const dialog=openDialog(i18nT('Yeni mağaza ekle'),i18nHtml`<form class="form-grid store-setup-form">
  <label class="full-width">Firma adı<input name="name" required maxlength="150" placeholder="Mağazanızın firma adı"></label>
  <fieldset class="store-setup-block full-width"><legend>Çalışma sistemi</legend>
   ${shippingScopeFields()}
   <div data-initial-inventory></div>
   <label data-shipment-start>İlk sevkiyat aktarımı<input name="shipmentStartDate" type="date" value="${first}" min="${first}" max="${today}" required></label>
   <p class="form-help" data-shipment-start>Pazaryeri bağlantısı kurulduğunda, bu tarihten itibaren API’den alınabilen uygun siparişler şehir dışı sevkiyata eklenir.</p>
  </fieldset>
  <fieldset class="customer-module-choices full-width"><legend>Çalışılan pazaryerleri</legend>
   <div class="store-setup-grid">${setupProviders.map(p=>i18nHtml`<label><input type="checkbox" name="marketplaces" value="${p.name}">${p.name}</label>`).join('')}</div>
   <small>Mağazanın çalıştığı pazaryerlerini seçin. API bilgilerini Müşteri Kurulumu bölümünden ekleyebilirsiniz.</small>
  </fieldset>
  ${onboardingOptions(template.configuration)}
  <p class="form-help full-width">Mağazayı oluşturduktan sonra Mağazalar → Müşteri Kurulumu bölümünden kullanıcı hesaplarını ve pazaryeri bağlantılarını hazırlayabilirsiniz.</p>
  <p class="form-error full-width" role="alert"></p>
  <button type="submit" class="btn primary" data-create-store>Mağazayı oluştur</button>
 </form>`,{className:'store-creation-dialog'});
 const form=dialog.querySelector('form'),shipping=bindShippingScope(form);
 const policy=bindInitialPermissions(form,template);
 const shipmentChoices=[...form.querySelectorAll('[name=liveSetupScreen],[name=manualSetupScreen]')].filter(input=>
  (input.name==='liveSetupScreen'?shipmentLiveOptions:shipmentManualOptions).has(input.value));
 const shipmentStart=form.elements.shipmentStartDate;
 const updateShipmentDate=()=>{
  shipmentStart.disabled=!shipmentChoices.some(input=>input.checked);
  form.querySelectorAll('[data-shipment-start]').forEach(element=>element.hidden=shipmentStart.disabled);
 };
 shipmentChoices.forEach(input=>input.addEventListener('change',updateShipmentDate));
 updateShipmentDate();
 form.onsubmit=async event=>{event.preventDefault();const button=form.querySelector('[data-create-store]');button.disabled=true;
  try{
   const submission=new FormData(form);
   const setupOptions=selectedOnboardingOptions(submission);
   await api('/companies','POST',{
    name:form.elements.name.value,
    shippingScope:shipping.value(),
    initialPolicy:policy.value(),
    marketplaces:submission.getAll('marketplaces'),
    setupOptions,
    shipmentStartDate:shipmentStart.disabled?null:shipmentStart.value,
    modules:{shipments:setupOptions.live.includes('shipments'),deliveries:setupOptions.live.includes('deliveries')},
   });
   dialog.close();await onCreated();toast(i18nT('Mağaza eklendi. Çalışma alanını açmak için listeden seçin.'));
  }
  catch(error){form.querySelector('.form-error').textContent=error.message;}finally{button.disabled=false;}
 };
}
export function bindCustomerActions(root,state,{onSave,onCreate,onSwitch,onAccountUpdated,onOpenStockList}){
 const openSelected=action=>()=>{
  const companyId=Number(state.company);
  if(state.user?.role!=='admin'||!state.companies?.some(company=>Number(company.id)===companyId))return;
  return action(companyId);
 };
 root.querySelector('#customerSetup')?.addEventListener('click',openSelected(companyId=>customerSetup(state,onSave,{
  companyId,
  onManageAccounts:setupReturn=>customerPermissions(state,onSave,{companyId,onAccountUpdated,setupReturn}),
 })));
 root.querySelector('#customerMarketplaces')?.addEventListener('click',openSelected(companyId=>customerMarketplaces(state,onSave,companyId)));
 root.querySelector('#customerPermissions')?.addEventListener('click',openSelected(companyId=>customerPermissions(state,onSave,{companyId,onAccountUpdated,...(onOpenStockList?{onOpenStockList}:{})})));
 root.querySelector('#manageCustomer')?.addEventListener('click',openSelected(companyId=>customerManagement(state,onSave,'profile',companyId)));
 root.querySelector('#newCompany')?.addEventListener('click',async event=>{
  const trigger=event.currentTarget,token=workspaceToken(state);
  trigger.disabled=true;
  trigger.setAttribute('aria-busy','true');
  try{await createCompany(onCreate,()=>sameWorkspace(state,token));}
  finally{trigger.disabled=false;trigger.removeAttribute('aria-busy');}
 });
 root.querySelector('[data-workspace-switch]')?.addEventListener('click',onSwitch);
 root.querySelector('#audit')?.addEventListener('click',async()=>{
  const token=workspaceToken(state);
  try{const rows=await api('/audit?company='+token.company);if(!sameWorkspace(state,token))return;
   openDialog(i18nT('İşlem geçmişi'),i18nHtml`<ul class="audit-list">${rows.map(row=>i18nHtml`<li><strong>${esc(row.action)}${row.record_id!=null?i18nText` · Kayıt ${Number(row.record_id)}`:''}</strong><span>${esc(row.name)} · ${esc(new Date(row.created).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}))}</span></li>`).join('')||i18nHtml('<li>Henüz işlem yok.</li>')}</ul>`);
  }catch(error){toast(error.message);}
 });
}
