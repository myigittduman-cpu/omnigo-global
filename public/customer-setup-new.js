import {contentError,loadingState} from './content-state.js';
import {api,upload} from './api.js';
import {html,t,text} from './i18n.js';
import {now,openDialog} from './ui.js';
import {bindShippingScope} from './shipping-scope.js';
import {bindInitialPermissions} from './customer-initial-permissions.js';
import {onboardingOptions,selectedOnboardingOptions} from './customer-onboarding-options.js';
import {setupProfileFields,setupSteps,setupView} from './customer-setup-view.js';
import {customerSetup} from './customer-setup.js';
import {customerPermissions} from './customer-permissions.js';
import {acceptDialogFieldDefaults,canLeaveDialogDrafts} from './dialog-drafts.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';

const shipmentLiveOptions=new Set(['shipments','deliveries','cityProducts','shipmentInvoices']);
const shipmentManualOptions=new Set(['shipmentEntry','operationCorrections','invoiceUpload','deliveryDocumentUpload']);
const profileKeys=['contactName','phone','email','address','notes'];

function creationForm(template){
 const today=now(),first=today.slice(0,7)+'-01';
 return html`<form class="form-grid store-setup-form" data-create-customer>
  ${setupProfileFields('')}
  <label>Mağaza logosu · isteğe bağlı<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
  <fieldset class="store-setup-block full-width"><legend>Çalışma sistemi</legend>
   <div data-initial-inventory></div>
   <label data-shipment-start>İlk sevkiyat aktarımı<input name="shipmentStartDate" type="date" value="${first}" min="${first}" max="${today}" required></label>
   <p class="form-help" data-shipment-start>Bağlantı kurulduğunda bu tarihten sonraki uygun siparişler şehir dışı sevkiyata eklenir.</p>
  </fieldset>
  ${onboardingOptions(template.configuration)}
 </form>`;
}

export async function newCustomerSetup(state,{onSave,onCreated,onAccountUpdated}={}){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state),same=()=>state.user?.role==='admin'&&sameWorkspace(state,token);
 const pending=loadingState('Kurulum hazırlanıyor…');
 const waitingDialog=openDialog(t('Müşteri Kurulumu'),pending,{className:'customer-setup-dialog',loading:true});
 const waitingBody=waitingDialog.querySelector('.dialog-body');
 const waiting=()=>same()&&waitingDialog.open&&waitingDialog.contains(waitingBody);
 let template;
 try{template=await api('/management/permission-template');}
 catch(error){if(waiting())waitingBody.innerHTML=contentError(error.message);return;}
 if(!waiting())return;
 const dialog=openDialog(t('Müşteri Kurulumu'),
  setupView({}, {profile:{},users:[]},[],{creationForm:creationForm(template),newCustomer:true}),
  {className:'customer-setup-dialog'});
 const form=dialog.querySelector('[data-create-customer]'),shipping=bindShippingScope(form);
 const policy=bindInitialPermissions(form,template);
 const start=form.elements.shipmentStartDate;
 const choices=[...form.querySelectorAll('[name=liveSetupScreen],[name=manualSetupScreen]')].filter(input=>
  (input.name==='liveSetupScreen'?shipmentLiveOptions:shipmentManualOptions).has(input.value));
 const updateShipmentDate=()=>{
  start.disabled=!choices.some(input=>input.checked);
  form.querySelectorAll('[data-shipment-start]').forEach(node=>node.hidden=start.disabled);
 };
 choices.forEach(input=>input.addEventListener('change',updateShipmentDate));
 updateShipmentDate();

 const back=dialog.querySelector('[data-setup-back]'),next=dialog.querySelector('[data-setup-next]');
 const counter=dialog.querySelector('[data-setup-counter]'),error=dialog.querySelector('[data-setup-error]');
 const current=()=>same()&&dialog.open&&dialog.contains(form);
 let step=0,busy=false,created=null;
 const paint=()=>{
  dialog.querySelectorAll('[data-step]').forEach(section=>section.hidden=Number(section.dataset.step)!==step);
  dialog.querySelectorAll('[data-step-label]').forEach(label=>{
   const index=Number(label.dataset.stepLabel);
   label.setAttribute('aria-current',index===step?'step':'false');
   label.classList.toggle('is-complete',index<step);
  });
  counter.textContent=text`Adım ${step+1} / ${setupSteps.length}`;
  back.disabled=step===0||busy||Boolean(created);
  next.disabled=busy;
  next.textContent=t(step===0?'Kuruluma başla':created?'Kuruluma devam et':'Müşteriyi oluştur ve ilerle');
 };
 const show=target=>{
  step=target;paint();
  const heading=dialog.querySelector(`[data-step="${target}"] h3`);
  heading?.setAttribute('tabindex','-1');heading?.focus({preventScroll:true});
 };
 back.onclick=async()=>{if(!busy&&!created&&await canLeaveDialogDrafts({withinDialog:true})&&current()&&!created)show(0);};
 next.onclick=()=>{if(step===0)show(1);else form.requestSubmit();};
 const blockClose=event=>{if(current()&&busy)event.preventDefault();};
 dialog.addEventListener('omnigo-dialog-dismiss',blockClose);
 dialog.addEventListener('close',()=>dialog.removeEventListener('omnigo-dialog-dismiss',blockClose),{once:true});
 form.onsubmit=async event=>{
  event.preventDefault();
  if(busy||step!==1||!current())return false;
  busy=true;paint();error.textContent='';
  next.textContent=t('Kurulum hazırlanıyor…');
  try{
   const logo=form.elements.logo.files[0];
   if(logo?.size>1048576)throw new Error(t('Logo en fazla 1 MB olmalı.'));
   if(!created){
    const fields=new FormData(form),name=String(fields.get('name')??'').trim();
    const setupOptions=selectedOnboardingOptions(fields);
    const {id}=await api('/companies','POST',{
     name,profile:Object.fromEntries(profileKeys.map(key=>[key,fields.get(key)])),
     shippingScope:shipping.value(),initialPolicy:policy.value(),marketplaces:[],setupOptions,
     shipmentStartDate:start.disabled?null:start.value,
     modules:{shipments:setupOptions.live.includes('shipments'),deliveries:setupOptions.live.includes('deliveries')},
    });
    if(!Number.isSafeInteger(id)||id<1)throw new Error(t('Yeni müşteri kaydı doğrulanamadı.'));
    created={id,name};
    if(!same())return false;
    state.companies=[...(state.companies??[]).filter(company=>Number(company.id)!==id),created];
    onCreated?.(state.companies);
    if(!current())return false;
    for(const field of form.elements)if(field!==form.elements.logo)field.disabled=true;
    acceptDialogFieldDefaults(form,[...form.elements].filter(field=>field!==form.elements.logo).map(field=>field.name).filter(Boolean));
   }
   if(logo){
    const body=new FormData();body.append('logo',logo);
    await upload('/management/setup/logo?company='+created.id,body);
    if(!current())return false;
    created.hasLogo=true;
    onCreated?.(state.companies);
    form.elements.logo.value='';
    acceptDialogFieldDefaults(form,['logo']);
   }
   const companyId=created.id;
   if(!await customerSetup(state,onSave,{companyId,newCustomer:true,resumeAccounts:true,
    onManageAccounts:setupReturn=>customerPermissions(state,onSave,{companyId,onAccountUpdated,setupReturn}),
   }))throw new Error(t('Müşteri oluşturuldu. Kuruluma devam etmek için yeniden deneyin.'));
   dialog.removeEventListener('omnigo-dialog-dismiss',blockClose);
   return true;
  }catch(failure){
   if(current()&&!failure.cancelled)error.textContent=failure.message;
   return false;
  }finally{busy=false;if(current())paint();}
 };
 paint();
 return dialog;
}
