import {bindShippingScope} from './shipping-scope.js';
import {addDialogBack,closeDialogThen} from './dialog-return.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,upload} from './api.js';
import {openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {setupView,setupSteps,reviewView,setupMarketStatus} from './customer-setup-view.js';
import {mountSetupConnections} from './customer-setup-connections.js';
import {acceptDialogFieldDefaults,canLeaveDialogDrafts} from './dialog-drafts.js';
import {ensureMarketplaceAccess,finishAccessCover} from './marketplace-access.js';
import {confirmAction} from './action-confirmation.js';
import {paintContent} from './content-paint.js';
import {createTransferPlan,transferStartFeedback} from './customer-transfer-plan.js';
import {showFeedback} from './save-feedback.js';

export async function customerSetup(state,onSave,{provider=null,onBack=null,companyId=null,onManageAccounts=null,resumeAccounts=false}={}){
 if(state.user?.role!=='admin')return;
 const token=workspaceToken(state);
 try{
  const selectedCompany=state.companies.find(c=>Number(c.id)===Number(companyId??token.company));
  if(!selectedCompany)throw new Error(i18nT('Mağaza seçin'));
  companyId=Number(selectedCompany.id);
  if(provider&&!await ensureMarketplaceAccess(state,selectedCompany))return;
  if(!sameWorkspace(state,token))return;
  const endpoint=path=>`/management/${provider?'':'setup/'}${path}?company=${companyId}`;
  const [data,catalog]=await Promise.all([api(endpoint('customer')),api(endpoint('marketplaces'))]);
  if(!sameWorkspace(state,token))return;
  const company={...selectedCompany};
  const firstStep=provider?4:0;
  let markets=catalog.items,step=resumeAccounts&&!provider?2:firstStep,busy=false,changed=false,health=null,connectionsMounted=false;
  const plan=provider?null:createTransferPlan({companyId,start:input=>api('/management/marketplaces/start?company='+companyId,'POST',input)});
  let configuration=null,started=null,setupRun=catalog.setupRun??null;
  const dialog=openDialog(provider?i18nT('Pazaryeri API bağlantısı'):i18nT('Müşteri Kurulumu'),
   setupView(company,data,markets,{provider,initialStep:step,canManageAccounts:Boolean(onManageAccounts)}),
   {className:'customer-setup-dialog',loading:true});
  if(onBack)addDialogBack(dialog,onBack,'Pazaryeri ayarlarına dön');
  const body=dialog.querySelector('.setup-layout');
  const current=()=>dialog.open&&body.isConnected&&dialog.contains(body)&&sameWorkspace(state,token),error=dialog.querySelector('[data-setup-error]'),status=dialog.querySelector('[data-setup-status]');
  const startButton=dialog.querySelector('[data-setup-start]'),review=dialog.querySelector('[data-setup-review]');
  const deferApi=dialog.querySelector('[data-defer-api]'),reviewRefresh=dialog.querySelector('[data-setup-refresh]');
  const connections=dialog.querySelector('[data-setup-connections]');
  const marketForm=dialog.querySelector('[data-setup-markets]');
  const marketInputs=[...marketForm.querySelectorAll('[data-setup-market]')];
  const mayStart=()=>!!configuration&&markets.some(m=>m.selected&&(m.configured||plan?.has(m.provider)));
  const markChanged=()=>{changed=true;};
  function syncMarketChoices(draft=new Map()){
   for(const input of marketInputs){
    const providerKey=input.dataset.setupMarket;
    const market=markets.find(entry=>entry.provider===providerKey);
    input.checked=market.selected;
    input.disabled=market.selected&&market.configured;
    input.closest('label').querySelector('[data-setup-market-status]').textContent=setupMarketStatus(market);
    if(!market.selected)plan?.discard(providerKey);
   }
   acceptDialogFieldDefaults(marketForm,marketInputs.map(input=>input.name));
   for(const input of marketInputs)if(!input.disabled&&draft.has(input.dataset.setupMarket)){
    input.checked=draft.get(input.dataset.setupMarket);
   }
  }
  const show=()=>{
   dialog.querySelectorAll('[data-step]').forEach(section=>section.hidden=Number(section.dataset.step)!==step);
   dialog.querySelectorAll('[data-step-label]').forEach(label=>{label.setAttribute('aria-current',Number(label.dataset.stepLabel)===step?'step':'false');label.classList.toggle('is-complete',Number(label.dataset.stepLabel)<step);});
   dialog.querySelector('[data-setup-back]').disabled=step<=firstStep||busy;
   dialog.querySelector('[data-setup-counter]').textContent=i18nText`Adım ${provider?step-3:step+1} / ${provider?2:setupSteps.length}`;
   dialog.querySelector('[data-setup-next]').textContent=step===5?(provider?i18nT('Tamamla'):i18nT('Kurulumu tamamla')):step===0?i18nT('Kuruluma başla'):[1,3].includes(step)?i18nT('Kaydet ve ilerle'):i18nT('İlerle');
   dialog.querySelector('[data-setup-next]').disabled=busy;
   deferApi.disabled=busy;reviewRefresh.disabled=busy;
   if(startButton)startButton.disabled=busy||!mayStart();
  };
  const activeSection=()=>body.querySelector(`[data-step="${step}"]`);
  const run=async(work,progress='',notifyError=false)=>{
   if(busy||!current())return false;
   busy=true;error.textContent='';status.textContent=progress;
   const section=activeSection();
   if(progress){section.setAttribute('aria-busy','true');body.setAttribute('data-setup-loading','');}
   show();
   try{return await work()!==false;}
   catch(e){if(current()&&!e.cancelled){error.textContent=e.message;if(notifyError)showFeedback(e.message,{kind:'error'});}return false;}
   finally{
    busy=false;
    if(progress){section.removeAttribute('aria-busy');body.removeAttribute('data-setup-loading');if(status.textContent===progress)status.textContent='';}
    if(current())show();
   }
  };
  const refreshReview=async()=>{
   const [fresh,report,accounts]=await Promise.all([api(endpoint('marketplaces')),api('/admin/connection-health?company='+companyId),api(endpoint('customer'))]);
   if(!current())return;markets=fresh.items;health=report;data.users=accounts.users;
   configuration=accounts.configuration??null;
   setupRun=fresh.setupRun??setupRun;
   paintContent(review,reviewView(provider?markets.filter(m=>m.provider===provider):markets,health,data.users,{plan,configuration,started,setupRun:provider?null:setupRun}));
  };
  if(startButton)startButton.onclick=()=>run(async()=>{
   if(!mayStart())return false;
   const result=await plan.send(configuration.version);
   if(!current())return false;
   started=result;
   setupRun=result.setupRun??setupRun;
   const feedback=transferStartFeedback(result);
   if(result.inventory==='scheduled'||Object.values(result.markets).some(market=>market.state==='scheduled'))markChanged();
   connectionsMounted=false;connections.replaceChildren();
   try{await refreshReview();}
   catch(error){
    // Preserve the committed queue result if refreshing connection status fails.
    if(current()){
     paintContent(review,reviewView(markets,health,data.users,{plan,configuration,started,setupRun}));
     dialog.querySelector('[data-setup-error]').textContent=error.message;
    }
   }
   finally{if(current())showFeedback(i18nT(feedback.message),{kind:feedback.kind,force:true});}
  },i18nT('Canlı aktarım hazırlanıyor…'),true);
  const stepProgress=target=>({4:i18nT('API bağlantıları hazırlanıyor…'),5:i18nT('Bağlantı durumları kontrol ediliyor…')})[target]??'';
  const enter=async target=>{
   const prior=activeSection();
   if(target!==step)prior.inert=true;
   try{
     if(target===4&&!connectionsMounted){
     await mountSetupConnections(connections,{company,markets:provider?markets.filter(m=>m.provider===provider):markets,current,onChange:markChanged,plan});
     connectionsMounted=current();
    }
    if(target===5)await refreshReview();
   }finally{prior.inert=false;}
   if(!current())return false;
   if(target===3)syncMarketChoices();
   step=target;show();
   dialog.querySelector('.dialog-body').scrollTop=0;
   const section=activeSection();section.scrollTop=0;
   const heading=section.querySelector('h3');
   heading?.setAttribute('tabindex','-1');heading?.focus({preventScroll:true});
  };
  const go=target=>run(()=>enter(target),stepProgress(target));
  dialog.querySelector('[data-setup-back]').onclick=async()=>{if(!busy&&await canLeaveDialogDrafts({withinDialog:true}))await go(Math.max(firstStep,step-1));};
  const profile=dialog.querySelector('[data-setup-profile]'),shipping=bindShippingScope(profile);
  const profileValues=()=>[...new FormData(profile)];
  let savedProfile=JSON.stringify(profileValues());
  profile.onsubmit=async event=>{
   event.preventDefault();
   return run(async()=>{
    if(!profile.reportValidity())return false;
    const scope=shipping.value(),fields=profileValues(),snapshot=JSON.stringify(fields);
    if(snapshot===savedProfile)return;
    const values=Object.fromEntries(fields);
    await api(endpoint('customer'),'PUT',{...values,shippingScope:scope,version:data.profile.version});
    savedProfile=snapshot;markChanged();
    data.profile=(await api(endpoint('customer'))).profile;
    company.name=values.name;dialog.querySelector('.setup-customer strong').textContent=values.name;
   });
  };
  marketForm.onsubmit=async event=>{event.preventDefault();return run(async()=>{
   const draft=new Map();
   for(const input of marketInputs){
    const key=input.dataset.setupMarket;
    const market=markets.find(entry=>entry.provider===key);
    if(!input.disabled&&input.checked!==market.selected)draft.set(key,input.checked);
   }
   if(!draft.size)return;
   for(const input of marketInputs)input.disabled=true;
   const requested=draft.size;
   let failure=null;
   try{
    for(const [key,selected] of draft){
     const market=markets.find(entry=>entry.provider===key);
     const payload=selected?{action:'add',version:market.version,storeName:market.storeName}
      :{action:'delete',version:market.version,unconfiguredOnly:true};
     await api(endpoint('marketplaces/'+key),'POST',payload);
     if(!current())return false;
     if(selected)Object.assign(market,{selected:true,version:market.version+1});
     else{
      Object.assign(market,{selected:false,version:0,storeName:''});
      plan?.discard(key);
     }
     draft.delete(key);
     markChanged();
    }
   }catch(error){failure=error;}
   if(!current())return false;
   connectionsMounted=false;
   connections.replaceChildren();
   try{
    const fresh=await api(endpoint('marketplaces'));
    if(!current())return false;
    markets=fresh.items;
   }catch(error){failure??=error;}
   if(!current())return false;
   syncMarketChoices(draft);
   if(failure?.cancelled&&draft.size<requested)throw new Error(i18nT('Kaydedilen seçimler uygulandı. Bekleyen seçimi tekrar kaydedebilirsiniz.'));
   if(failure)throw failure;
  });};
  let confirmingClose=false;
  const protectPrepared=event=>{if(dialog.open&&plan?.count){event.preventDefault();event.returnValue='';}};
  window.addEventListener('beforeunload',protectPrepared);
  async function canCloseSetup(){
   if(confirmingClose)return false;
   if(plan?.count){
    confirmingClose=true;
    try{
     if(!await confirmAction(i18nT('Hazırlanan API bilgileri bu pencereyle birlikte silinsin mi?'),{approveLabel:i18nT('Hazırlığı sil ve kapat'),scope:company.name}))return false;
    }finally{confirmingClose=false;}
   }
   return current();
  }
  async function closeSetup(){if(await canCloseSetup())dialog.close();}
  const accountsButton=dialog.querySelector('[data-setup-manage-accounts]');
  if(accountsButton)accountsButton.onclick=()=>run(async()=>{
   if(step!==2)return false;
   accountsButton.disabled=true;
   try{
    if(!await canCloseSetup()||!await ensureMarketplaceAccess(state,selectedCompany)||!current())return;
    const resume=()=>sameWorkspace(state,token)&&customerSetup(state,onSave,{companyId,onManageAccounts,resumeAccounts:true});
    await closeDialogThen(dialog,()=>onManageAccounts(resume));
   }finally{if(current())accountsButton.disabled=false;finishAccessCover();}
  },i18nT('Kullanıcı ayarları hazırlanıyor…'),true);
  dialog.addEventListener('omnigo-dialog-dismiss',event=>{
   if(!current())return;
   if(busy||plan?.count)event.preventDefault();
   if(!busy&&plan?.count)void closeSetup();
  });
  dialog.querySelector('[data-setup-next]').onclick=async()=>{
   if(busy||!current())return;
   if(step===1&&await profile.onsubmit(new Event('submit',{cancelable:true}))===false)return;
   if(step===3&&await marketForm.onsubmit(new Event('submit',{cancelable:true}))===false)return;
   if(!(await canLeaveDialogDrafts({withinDialog:true})))return;
   if(step!==5){await go(step+1);return;}
   await run(async()=>{
    await refreshReview();if(!current())return;
    const pending=(provider?markets.filter(m=>m.provider===provider):markets).filter(m=>m.selected).some(m=>!m.configured||health?.items.find(i=>i.channel===m.name)?.status!=='ready');
    await closeSetup();
    if(!dialog.open)toast(pending?i18nT('Bekleyen bağlantıları Pazaryeri ayarlarından tamamlayabilirsiniz.'):i18nT('Aktarım durumları Bağlantı kontrolünden izlenebilir.'));
   },stepProgress(5));
  };
  show();
  const logo=dialog.querySelector('[data-setup-logo]');logo.onsubmit=async event=>{event.preventDefault();return run(async()=>{if(!logo.elements.logo.files[0])throw new Error(i18nT('Logo dosyasını seçin.'));if(logo.elements.logo.files[0].size>1048576)throw new Error(i18nT('Logo en fazla 1 MB olmalı.'));await upload(endpoint('logo'),new FormData(logo));logo.reset();markChanged();data.profile=(await api(endpoint('customer'))).profile;if(current())status.textContent=i18nT('Logo kaydedildi; İş Ortağım’a da yansır.');});};
  deferApi.onclick=async()=>{if(!busy&&await canLeaveDialogDrafts({withinDialog:true}))await go(5);};
  reviewRefresh.onclick=()=>run(async()=>{
   await refreshReview();
   if(current())showFeedback(i18nT('Bağlantı durumları güncellendi.'),{kind:'success',force:true});
  },stepProgress(5),true);
  dialog.addEventListener('close',()=>{
   window.removeEventListener('beforeunload',protectPrepared);plan?.clear();
   body.querySelectorAll('input[type=password]').forEach(input=>input.value='');
   if(changed&&sameWorkspace(state,token))void onSave?.();
  },{once:true});
  await go(step);
 }catch(error){if(sameWorkspace(state,token))toast(error.message);}finally{finishAccessCover();}
}
