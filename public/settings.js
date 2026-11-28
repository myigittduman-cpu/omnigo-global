import {managementHome,bindManagementHome} from './application-management.js';
import {bindUsernameSettings} from './account-username.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {applyPreferences,loadPreferences} from './user-preferences.js';
import {settingsSections,settingsContent} from './settings-view.js';
import {openSettingsAccountPage} from './settings-account-page.js';
import {bindPasswordChange} from './password-change.js';
import {bindSessionPreference} from './session-preferences.js';
import {createSettingsDraftGuard} from './settings-drafts.js';
import {bindSettingsActivity} from './settings-activity.js';
import {customerPageVisible} from './customer-access.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {bindTableSync,tableSyncPanel} from './settings-table-sync.js';
import {canUseMarketplaceStockPilot} from './marketplace-stock-pilot.js';
import {mountStockPilotSettings} from './marketplace-stock-settings.js';
import {closeDialogThen} from './dialog-return.js';
import {workspaceMenuLabelText} from './workspace-menu-labels.js';
function loadSettingsLayout(){
 for(const [href,key] of [['/settings-layout.css','settingsLayout']]){
  if(document.querySelector(`link[data-${key.replace(/[A-Z]/g,char=>'-'+char.toLowerCase())}]`))continue;
  const link=document.createElement('link');link.rel='stylesheet';link.href=href;link.dataset[key]='';document.head.append(link);
 }
}
export function bindSettings(root,state,options={}){
 const button=root.querySelector('[data-open-settings]');if(button)button.onclick=()=>openSettings(state,options);
}
export async function openSettings(state,options={}){
 loadSettingsLayout();
 const {onPasswordChanged,beforeLanguageChange,onLanguageChanged,onSessionEnded,initialSection='account',initialScroll=0}=options;
 const user=state.user,drafts=createSettingsDraftGuard(),workspace=workspaceToken(state);let saved;
 const activeCompany=(state.companies??[]).find(item=>Number(item.id)===Number(workspace.company));
 const company=customerPageVisible(state,'shipments')?activeCompany:null;
 const sections=user.role==='admin'&&activeCompany?{...settingsSections,transfers:['Veri Aktarımı','Seçili mağazanın canlı tabloları']}:settingsSections;
 const dialog=openDialog(i18nT('Ayarlar'),i18nHtml`<p role="status">Ayarlar yükleniyor…</p>`,{className:'settings-dialog',draftGuard:drafts,loading:true}),body=dialog.querySelector('.dialog-body');
 const active=()=>dialog.open&&body.isConnected&&state.user===user&&sameWorkspace(state,workspace);
 try{saved=user.preferences||await loadPreferences(user);}catch(error){if(active())body.innerHTML=i18nHtml`<p class="form-error" role="alert">${esc(error.message)}</p>`;return;}
 if(!active())return;
 const categories=user.role==='admin'&&saved.canManageApplication===true?{...sections,management:['Uygulama Yönetimi','Ortak düzen ve ayarların kapsamı'],history:['Değişiklik geçmişi','Yönetim ve hesap hareketleri']}:sections;
 let section=Object.hasOwn(categories,initialSection)?initialSection:'account';
 let detail=null,switching=false,passwordObserver,stopTableSync;
 const heading=i18nHtml`<strong>${esc(user.operatorName||user.name)}</strong> için kişisel ayarlar`;
 body.innerHTML=i18nHtml`<p class="settings-profile">${heading}</p><div class="settings-layout"><nav class="settings-nav" aria-label="Ayar kategorileri">${Object.entries(categories).map(([id,[label,help]])=>i18nHtml`<button type="button" data-settings-section="${id}"><strong>${i18nT(label,id==='account'?'account':undefined)}</strong><small>${i18nT(help)}</small></button>`).join('')}</nav><section class="settings-content"></section></div>`;
 const panel=body.querySelector('.settings-content');
 const savePreferences=async(preferences,message)=>{
  const error=body.querySelector('[data-settings-error]'),status=body.querySelector('[data-settings-status]');
  error.textContent='';status.textContent='';
  try{const result=await api('/account/preferences','PUT',{version:saved.version,preferences});if(!active())return false;saved=result;user.preferences=result;applyPreferences(result.preferences);status.textContent=message;return true;}
  catch(failure){if(active())error.textContent=failure.message;return false;}
 };
 const paint=()=>{
  passwordObserver?.disconnect();
  stopTableSync?.();
  const transfers=section==='transfers'&&user.role==='admin'&&activeCompany;
  const management=section==='management'&&saved.canManageApplication===true&&user.role==='admin';
  const content=management?managementHome(Boolean(activeCompany),Boolean(company)):transfers?tableSyncPanel(activeCompany):settingsContent(section,user,saved.preferences,company);
  panel.setAttribute('aria-label',i18nT(categories[section][0],section==='account'?'account':undefined));
  panel.innerHTML=i18nHtml`${content}<p class="form-error" data-settings-error role="alert"></p><p data-settings-status role="status"></p>`;
  for(const button of body.querySelectorAll('[data-settings-section]'))button.setAttribute('aria-pressed',String(button.dataset.settingsSection===section));
  if(management)bindManagementHome(panel,{
   drafts,
   current:()=>active()&&section==='management',
   openCustomers:()=>closeDialogThen(dialog,()=>sameWorkspace(state,workspace)&&options.onNavigatePage?.('customers'))
  });
  if(transfers){
   const transferOpen=()=>active()&&section==='transfers';
   stopTableSync=bindTableSync(panel,activeCompany,transferOpen,table=>workspaceMenuLabelText(state,table.key,table.label));
   if(options.onOpenStockList&&canUseMarketplaceStockPilot(state)){
    const header=panel.querySelector('.settings-table-sync header');
    const stockSettings=mountStockPilotSettings(header,{
     company:activeCompany.id,current:()=>header.isConnected&&transferOpen()&&canUseMarketplaceStockPilot(state),
     openList:()=>closeDialogThen(dialog,()=>sameWorkspace(state,workspace)&&options.onOpenStockList())
    });
    void stockSettings.refresh();
    panel.querySelector('[data-sync-refresh]')?.addEventListener('click',()=>void stockSettings.refresh());
   }
  }
  if(section==='history'||section==='sessions')bindSettingsActivity(body.querySelector('[data-settings-activity]'),state,{current:active});
  const layoutForm=body.querySelector('[data-settings-shipment-layout]');
  if(layoutForm)drafts.track(layoutForm,{label:i18nT('Tablo düzeni'),save:async values=>{
   const table=document.querySelector('[data-shipments]');
   const error=body.querySelector('[data-settings-error]');
   if(!sameWorkspace(state,workspace)){error.textContent=i18nT('Mağaza değişti. Ayarları yeniden açın.');return false;}
   if(table?.shipmentLayoutBusy?.()){error.textContent=i18nT('Önce düzenleme işlemlerini kaydedin veya düzenleme modundan çıkın.');return false;}
   const shipmentLayouts={...saved.preferences.shipmentLayouts,[company.id]:values.shipmentLayout};
   const applied=await savePreferences({...saved.preferences,shipmentLayouts},i18nT('Tablo düzeni kaydedildi.'));
   if(applied)table?.shipmentLayoutChanged?.();
   return applied;
  }});
  const languageForm=body.querySelector('[data-language]');
  const themeForm=body.querySelector('[data-theme-preference]');
  if(themeForm)drafts.track(themeForm,{label:i18nT('Tema'),save:values=>
   savePreferences({...saved.preferences,theme:values.theme,menuStyle:values.menuStyle},i18nT('Tema ve menü tercihiniz kaydedildi.'))
  });
  if(languageForm)drafts.track(languageForm,{label:i18nT('Dil'),save:async values=>{
   if(await beforeLanguageChange?.()===false||!active())return false;
   if(!await savePreferences({...saved.preferences,language:values.language},''))return false;
   dialog.close();await onLanguageChanged?.();toast(i18nT('Dil tercihiniz kaydedildi.'));return true;
  }});
  if(section==='account'){
   bindUsernameSettings(body,user,{drafts,active});
   const passwordArea=body.querySelector('[data-password-area]');
   bindPasswordChange(passwordArea,async()=>{dialog.close();await onPasswordChanged?.();toast(i18nT('Şifreniz değişti. Yeni şifrenizle giriş yapın.'));});
   const trackPassword=()=>passwordArea.querySelectorAll('form').forEach(form=>drafts.track(form,{label:i18nT('Şifre değiştir'),manageButton:false}));
   trackPassword();passwordObserver=new MutationObserver(trackPassword);passwordObserver.observe(passwordArea,{childList:true,subtree:true});
   bindSessionPreference(body.querySelector('[data-session-preference]'),{active,drafts,beforeSignOut:beforeLanguageChange,onSignedOut:async()=>{dialog.close();await onSessionEnded?.();}});
  }
 };
 const scrollBySection=new Map();
 body.addEventListener('click',async event=>{
  const tab=event.target.closest('[data-settings-section]');
  const link=event.target.closest('a.settings-account-link');
  if(!tab&&!link)return;
  if(link)event.preventDefault();
  if(switching||!active())return;
  if(tab&&tab.dataset.settingsSection===section&&!detail)return;
  switching=true;
  try{
   if(detail&&(!await detail.close()||!active()))return;
   const resetDrafts=drafts.hasOtherChanges(null);
   if(!await drafts.canLeave()||!active())return;
   if(tab){
    scrollBySection.set(section,body.scrollTop);
    section=tab.dataset.settingsSection;paint();body.scrollTop=scrollBySection.get(section)||0;
    tab.focus({preventScroll:true});return;
   }
   const scroll=body.scrollTop,panelScroll=panel.scrollTop,href=link.href;
   if(resetDrafts)paint();
   detail=openSettingsAccountPage(href,state,{host:dialog,panel,isCurrent:active,onClose:()=>{
    detail=null;
    if(!active())return;
    if(section==='management')paint();
    const restored=Array.from(panel.querySelectorAll('a.settings-account-link')).find(item=>item.href===href);
    restored?.focus({preventScroll:true});body.scrollTop=scroll;panel.scrollTop=panelScroll;
   }});
   if(detail){body.scrollTop=0;panel.scrollTop=0;}
  }finally{switching=false;}
 });
 dialog.addEventListener('close',()=>{passwordObserver?.disconnect();stopTableSync?.();},{once:true});
 paint();body.scrollTop=initialScroll;requestAnimationFrame(()=>{if(active())document.dispatchEvent(new Event('omnigo-dialog-ready'));});
}
