import {choosePermissionStore,permissionStoreSelector,bindPermissionStore} from './customer-permission-store.js';
import {confirmAction,confirmMutation} from './action-confirmation.js';
import {permissionDefaults} from './permission-user-application.js';
import {bindPermissionTabs} from './customer-permission-tabs.js';
import {permissionManagementPanels} from './customer-permission-management.js';
import {loadPermissionDialog} from './customer-permission-dialog.js';
import {customerNotificationSettings} from './customer-notifications.js';
import {customerCitySettings} from './customer-city-settings.js';
import {turkeyCities} from './turkey-cities.js';
import {t,html,text} from './i18n.js';
import {api} from './api.js';
import {toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {confirmPendingChanges} from './pending-changes.js';
import {permissionSections,permissionMatrix} from './customer-permissions-view.js';
import {permissionAccountList,personalPermissionSettings,updatePermissionAccountRole} from './customer-permission-accounts.js';
import {canUseMarketplaceStockPilot} from './marketplace-stock-pilot.js';
import {mountStockPilotSettings} from './marketplace-stock-settings.js';
import {closeDialogThen} from './dialog-return.js';
import {inventoryModeChoices} from './inventory-mode-setting.js';
import {applyPermissionChoice,permissionChoiceValue} from './permission-grant-choice.js';
import {inventoryModeSelection} from './inventory-management-model.js';
import {ensureMarketplaceAccess,finishAccessCover} from './marketplace-access.js';
import {sectionAllowsRole} from './customer-section-audience.js';
import {onboardingOptions,selectedOnboardingOptions} from './customer-onboarding-options.js';

function permissionForm(companies,companyId,configuration){
 const scope=configuration.setupOptions==null?'':html`<div class="form-grid" data-permission-work-scope>${onboardingOptions(configuration)}</div>`;
 return html`${permissionStoreSelector(companies,companyId)}
  <form class="permission-form">
   <div data-permission-overview>
    <details class="permission-sections"><summary>Mağaza erişimi</summary><div data-permission-sections></div></details>
    <section class="permission-accounts" aria-labelledby="permission-accounts-title">
     <h4 id="permission-accounts-title">Mağaza kullanıcıları</h4>
     <div data-permission-account-list></div>
    </section>
   </div>
   <div data-permission-users>
    <button type="button" class="btn permission-user-back" data-permission-back hidden></button>
    <section id="permission-account-details" class="permission-personal" data-personal-permissions aria-labelledby="permission-personal-title" hidden>
     <div data-personal-heading></div><div data-permission-matrix></div>
    </section>
   </div>
   <div data-city-settings></div><div data-notification-settings></div>${scope}
   <p class="form-error" role="alert"></p>
   <footer class="permission-footer"><span data-permission-status role="status"></span><button class="btn primary" type="submit">Kaydet</button></footer>
  </form>`;
}

function permissionChanges(configuration,baseline,roleResets=new Set()){
 const {users,roles,roleDefaults,newRoleDefaults,newRoleDefaultVersions,usesStoreDefaultsForNewAccounts,inventoryManagement,navigationLayouts,...settings}=configuration;
 const userChanges={};
 for(const [id,grants] of Object.entries(users)){
  if(!roleResets.has(id)&&JSON.stringify(baseline.users[id])!==JSON.stringify(grants))userChanges[id]=grants;
 }
 const roleChanges=Object.fromEntries(Object.entries(roles).filter(([id,role])=>baseline.roles[id]!==role));
 const storeChanges=Object.fromEntries(Object.entries(settings).filter(([key,value])=>key==='version'||JSON.stringify(baseline[key])!==JSON.stringify(value)));
 return {...storeChanges,userChanges,roleChanges,roleResets:[...roleResets].map(Number),applyDefaultsToNewAccounts:usesStoreDefaultsForNewAccounts===true};
}

export async function customerPermissions(state,onSave,{selectedUser='',companyId=null,onBack=null,onAccountUpdated,onOpenStockList}={}){
 if(state.user?.role!=='admin')return;
 const openStore=id=>customerPermissions(state,onSave,{companyId:id,onAccountUpdated,onOpenStockList});
 if(!companyId)return choosePermissionStore(state,openStore);
 const company=state.companies.find(item=>Number(item.id)===Number(companyId));
 if(!company)return;
 const token=workspaceToken(state),endpoint='/management/permissions?company='+Number(companyId);
 try{
  if(!await ensureMarketplaceAccess(state,company)||!sameWorkspace(state,token))return;
  const loaded=await loadPermissionDialog(()=>api(endpoint),()=>state.user?.role==='admin'&&sameWorkspace(state,token));
  if(!loaded)return;
  const {data}=loaded;
  const accounts=new Map(data.users.map(account=>[String(account.id),account]));
  let configuration=structuredClone(data.configuration),baseline=JSON.stringify(configuration);
  let captionRequest=0;const captionEvents=new AbortController();
  let userId=accounts.has(String(selectedUser))?String(selectedUser):'',busy=false,pending=null,dialog,form,management,changed=false;
  const roleResets=new Set();
  const current=()=>dialog?.open&&form?.isConnected&&dialog.contains(form)&&state.user?.role==='admin'&&sameWorkspace(state,token);
  const dirty=()=>roleResets.size>0||JSON.stringify(configuration)!==baseline;
  const unload=event=>{if(current()&&(dirty()||busy)){event.preventDefault();event.returnValue='';}};
  async function canLeaveStore(){
   if(busy)return false;
   if(!dirty())return true;
   if(pending)return pending;
   const changes=[t('Müşteri bölümleri ve kullanıcı yetkileri')];
   if(JSON.stringify(configuration.setupOptions)!==JSON.stringify(JSON.parse(baseline).setupOptions))changes.push(t('Çalışma kapsamı'));
   pending=confirmPendingChanges({source:company.name,changes,current,save,discard:()=>{roleResets.clear();configuration=JSON.parse(baseline);paint();return true;}}).finally(()=>pending=null);
   return pending;
  }
  const guard={
   attach(){window.addEventListener('beforeunload',unload);},
   dispose(){window.removeEventListener('beforeunload',unload);captionEvents.abort();management?.unmount();},
   canLeave:()=>management?.canLeave()??canLeaveStore(),
  };
  dialog=loaded.mount(permissionForm(state.companies,companyId,configuration),guard);
  if(!dialog)return;
  form=dialog.querySelector('form');
  const back=form.querySelector('[data-permission-back]');
  form.dataset.menuLabelSnapshot=String(companyId);
  const workScope=form.querySelector('[data-permission-work-scope]');
  const accountList=form.querySelector('[data-permission-account-list]');
  const personal=form.querySelector('[data-personal-permissions]');
  const heading=form.querySelector('[data-personal-heading]');
  const matrix=form.querySelector('[data-permission-matrix]');
  management=permissionManagementPanels(state,{companyId,dialog,current,blocked:()=>busy,canLeaveStore,reloadStore,
   onAccountUpdated,onUpdated:key=>{if(key==='accounts')changed=true;}});
  bindPermissionStore(dialog,state,companyId,{blocked:()=>busy||management.switching(),onSelect:openStore});
  let pilotSettings;
  const tabs=bindPermissionTabs(form,{...management,onSelect(key,panel,ready){
   management.onSelect(key,panel,ready);
   if(key==='inventory')void pilotSettings?.refresh();
  }},selectedUser||onBack?'sections':'accounts');

  if(state.user?.role==='admin'){
   const toggle=document.createElement('button');toggle.type='button';toggle.className='btn secondary';toggle.disabled=true;
   toggle.textContent='Pazaryeri stok ve fiyat modeli';
   form.querySelector('[data-inventory-model-settings]').after(toggle);
   const url='/inventory/active-marketplace-listings?model=1&company='+encodeURIComponent(companyId);
   let enabled;
   const paint=value=>{enabled=value;toggle.textContent=value?'Pazaryeri stok ve fiyat modelini kapat':'Pazaryeri stok ve fiyat modelini aç';toggle.disabled=false;};
   api(url).then(r=>{if(current())paint(r.enabled);}).catch(e=>{if(current())toggle.textContent=e.message;});
   toggle.onclick=async()=>{if(!current()||toggle.disabled)return;toggle.disabled=true;
    try{const r=await api(url,'POST',{enabled:!enabled},{confirmed:true});if(current()){paint(r.enabled);const row=state.companies?.find?.(c=>Number(c.id)===Number(companyId));if(row)row.marketplaceStockPilot=r.enabled;}}
    catch(e){if(current()){toggle.textContent=e.message;toggle.disabled=false;}}
   };
  }
  if(onOpenStockList&&Number(state.company)===Number(companyId)&&canUseMarketplaceStockPilot(state)){
   pilotSettings=mountStockPilotSettings(form.querySelector('[data-inventory-model-settings]'),{
    company:companyId,current:()=>current()&&canUseMarketplaceStockPilot(state),
    openList:()=>closeDialogThen(dialog,()=>sameWorkspace(state,token)&&onOpenStockList())
   });
  }
  const dialogBody=dialog.querySelector('.dialog-body');
  const panels=form.querySelector('.permission-panels');
  const overview=form.querySelector('[data-permission-overview]');
  const accessHeading=form.querySelector('#permission-panel-sections > .permission-panel-heading');
  accountList.innerHTML=permissionAccountList(data.users,configuration);
  let accountTable=accountList.querySelector('.permission-account-scroll');
  let listPosition=null;
  async function reloadStore(){
   const next=await api(endpoint);
   if(!current())return false;
   Object.assign(data,next);
   accounts.clear();
   for(const account of data.users)accounts.set(String(account.id),account);
   if(!accounts.has(userId)){userId='';listPosition=null;}
   configuration=structuredClone(data.configuration);baseline=JSON.stringify(configuration);
   accountList.innerHTML=permissionAccountList(data.users,configuration);
   accountTable=accountList.querySelector('.permission-account-scroll');
   paint();
   return true;
  }

  function paintAccounts(){
   const editing=Boolean(userId);
   personal.toggleAttribute('data-permission-user-selected',editing);
   overview.hidden=accessHeading.hidden=editing;
   personal.hidden=!editing;
   back.hidden=!editing&&!onBack;
   back.textContent='← '+t(editing?'Kullanıcı listesine dön':'Hesap dizinine dön');
   if(editing){
    heading.innerHTML=personalPermissionSettings(accounts.get(userId),configuration);
    return;
   }
   heading.replaceChildren();
  }
  function openAccount(id){
   listPosition={body:dialogBody.scrollTop,panels:panels.scrollTop,left:accountTable.scrollLeft,top:accountTable.scrollTop};
   userId=id;
   paintAccounts();
   paintMatrix({keepExpanded:false});
   dialogBody.scrollTop=panels.scrollTop=0;
   heading.querySelector('#permission-personal-title').focus({preventScroll:true});
  }
  back.onclick=async()=>{
   if(busy||!current())return;
   if(onBack&&!listPosition){
    if(!await guard.canLeave()||!current())return;
    if(onBack()&&changed){changed=false;void onSave?.();}
    return;
   }
   const previous=userId;
   if(previous)updatePermissionAccountRole(accountList,accounts.get(previous),configuration);
   userId='';
   paintAccounts();
   paintMatrix({keepExpanded:false});
   accountList.querySelector(`[data-permission-account="${Number(previous)}"]`)?.focus({preventScroll:true});
   if(listPosition){
    panels.scrollTop=listPosition.panels;
    dialogBody.scrollTop=listPosition.body;
    accountTable?.scrollTo({left:listPosition.left,top:listPosition.top,behavior:'instant'});
    listPosition=null;
   }
  };
  async function refreshPermissionCaptions(){
   if(!form.isConnected||!dialog.contains(form))return;
   const id=userId,request=++captionRequest;
   try{
    const query='/management/menu-labels?company='+Number(companyId)+(id?'&scope=user&userId='+Number(id):'&scope=store');
    const response=await api(query);if(!current()||!form.isConnected||!dialog.contains(form)||request!==captionRequest||userId!==id)return;
    const names=response.targetEffective||{version:0,labels:{}};const labels=names.labels;
    if(data.menuLabels){if(id)data.menuLabels.users[id]=names;else data.menuLabels.store=names;}
    for(const caption of form.querySelectorAll('[data-workspace-menu-label]')){
     const key=caption.dataset.workspaceMenuLabel;caption.textContent=Object.hasOwn(labels,key)?labels[key]:caption.dataset.workspaceMenuDefault;
    }
   }catch{ /* A caption refresh must not alter grants or an unsaved form. */ }
  }
  function permissionLabelState(id=''){
   const names=(id?data.menuLabels?.users?.[id]:data.menuLabels?.store)||{version:0,labels:{}};
   return {...state,company:Number(companyId),workspaceMenuLabels:{company:Number(companyId),context:state.contextRevision||0,revision:names.version,labels:names.labels}};
  }
  function paintMatrix({keepExpanded=true}={}){
   const expanded=new Set(keepExpanded?[...matrix.querySelectorAll('[data-permission-actions][open]')].map(el=>el.dataset.permissionActions):[]);
   const focused=matrix.contains(document.activeElement)?document.activeElement.dataset:null;
   matrix.innerHTML=userId?permissionMatrix(data.catalog,data.actions,configuration,userId,{expanded,state:permissionLabelState(userId)}):'';
   void refreshPermissionCaptions();
   if(focused?.permission)[...matrix.querySelectorAll('[data-permission]')].find(el=>el.dataset.page===focused.page&&el.dataset.permission===focused.permission)?.focus({preventScroll:true});
  }
  document.addEventListener('omnigo-menu-labels-changed',()=>{if(current())void refreshPermissionCaptions();},{signal:captionEvents.signal});
  function updateStatus(){
   form.querySelector('[type=submit]').disabled=busy||!dirty();
   form.querySelector('[data-permission-status]').textContent=dirty()?t('Kaydedilmemiş değişiklikler var.'):t('Kaydedilmiş ayarlar gösteriliyor.');
  }
  function paint(){
   form.querySelector('[data-notification-settings]').innerHTML=customerNotificationSettings(configuration);
   form.querySelector('[data-city-settings]').innerHTML=customerCitySettings(configuration.cities);
   form.querySelector('[data-inventory-model-settings]').innerHTML=inventoryModeChoices(configuration);
   form.querySelector('[data-permission-sections]').innerHTML=permissionSections(data.catalog,configuration,{includeInventoryMode:false,state:permissionLabelState()});
   void refreshPermissionCaptions();
   if(workScope)for(const input of workScope.querySelectorAll('input[type=checkbox]')){
    const selected=input.name==='liveSetupScreen'?configuration.setupOptions.live:configuration.setupOptions.manual;
    input.checked=selected.includes(input.value);
   }
   paintAccounts();paintMatrix();tabs.refresh();updateStatus();
  }
  function selectRole(role,{restore=false}={}){
   if(!userId||!Object.hasOwn(configuration.newRoleDefaults,role))return;
   if(restore)roleResets.add(userId);
   else roleResets.delete(userId);
   const previous=JSON.parse(baseline);
   configuration.roles[userId]=role;
   if(!restore&&previous.roles[userId]===role){
    if(Object.hasOwn(previous.users,userId))configuration.users[userId]=previous.users[userId];
    else delete configuration.users[userId];
   }else configuration.users[userId]=structuredClone(configuration.newRoleDefaults[role]);
   paintAccounts();paintMatrix({keepExpanded:false});updateStatus();
   heading.querySelector(restore?'[data-load-role-defaults]':'[data-user-store-role]')?.focus({preventScroll:true});
  }
  async function restoreRoleDefaults(){
   const account=accounts.get(userId);
   const role=heading.querySelector('[data-user-store-role]').value;
   const message=text`${account.name} için kişiye özel izinler seçili rolün varsayılanlarıyla değiştirilsin mi? Yeni izinler Kaydet ile uygulanır.`;
   if(!await confirmAction(message,{heading:t('Varsayılana dön'),approveLabel:t('Varsayılanı uygula')}))return;
   if(current()&&!busy&&accounts.get(userId)===account)selectRole(role,{restore:true});
  }
  async function save(){
   if(busy||!current())return false;
   if(!dirty())return true;
   busy=true;form.querySelector('.form-error').textContent='';
   const controls=[...form.querySelectorAll('button,input,select')].map(el=>[el,el.disabled]);
   controls.forEach(([el])=>el.disabled=true);
   try{
    const outgoing=permissionChanges(configuration,JSON.parse(baseline),roleResets);
    await confirmMutation(endpoint,'PUT',{targetName:company.name});
    if(!current())return false;
    const result=await api(endpoint,'PUT',outgoing,{confirmed:true});
    if(!current())return false;
    configuration=result.configuration;baseline=JSON.stringify(configuration);roleResets.clear();changed=true;
    toast(t('Müşteri yetkileri kaydedildi.'));
    return true;
   }catch(error){if(current())form.querySelector('.form-error').textContent=error.message;return false;}
   finally{busy=false;if(current()){controls.forEach(([el,disabled])=>el.disabled=disabled);paint();}}
  }
  form.onsubmit=event=>{event.preventDefault();return save();};
  form.addEventListener('change',event=>{
   if(busy)return;
   const input=event.target;
   if(workScope?.contains(input)){
    configuration.setupOptions=selectedOnboardingOptions(new FormData(form));
    updateStatus();
    return;
   }
   if(input.dataset.customerNotification)configuration.notifications[input.dataset.customerNotification]=input.checked;
   if(input.hasAttribute('data-city-setting'))configuration.cities=[...form.querySelectorAll('[data-city-setting]:checked')].map(el=>el.value);
   if(input.hasAttribute('data-user-store-role'))selectRole(input.value);
   if(input.hasAttribute('data-inventory-mode')){
    configuration.inventory=inventoryModeSelection(input.value,configuration.inventoryManagement);
    paintMatrix();
   }
   if(input.dataset.section){
    const page=input.dataset.section;
    configuration.pages[page]=input.value;
    if(sectionAllowsRole(page,input.value,'store_manager'))configuration.defaults[page].view=true;
    form.querySelector('[data-notification-settings]').innerHTML=customerNotificationSettings(configuration);
    paintMatrix();
   }
   if(input.dataset.permission&&userId){
    roleResets.delete(userId);
    configuration.users[userId]??=structuredClone(permissionDefaults(configuration,userId));
    applyPermissionChoice(configuration.users[userId],input.dataset.page,input.dataset.permission,permissionChoiceValue(input));
    paintMatrix();
   }
   updateStatus();
  });
  form.addEventListener('input',event=>{if(event.target.hasAttribute('data-city-settings-search'))tabs.searchCities(event.target.value.toLocaleLowerCase('tr'));});
  form.addEventListener('click',event=>{
   if(busy)return;
   const button=event.target.closest('button');
   if(!button)return;
   if(accounts.has(button.dataset.permissionAccount)){
    openAccount(button.dataset.permissionAccount);
    return;
   }
   if(button.hasAttribute('data-load-role-defaults'))void restoreRoleDefaults();
   if(button.hasAttribute('data-city-settings-all')){configuration.cities=[...turkeyCities];paint();}
   if(button.hasAttribute('data-city-settings-none')){configuration.cities=[];paint();}
  });
  dialog.addEventListener('close',()=>{if(changed&&sameWorkspace(state,token))void onSave?.();},{once:true});
  paint();
 }catch(error){if(sameWorkspace(state,token))toast(error.message);}finally{finishAccessCover();}
}

