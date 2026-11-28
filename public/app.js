import {workspaceMenuDefaultLabel,workspaceMenuLabelMarkup} from './workspace-menu-labels.js';
import {notificationView,hasNotificationDetail} from './notification-destination.js';
import {workspaceTableHeading} from './workspace-table-heading.js';
import {registerUpdateContext,pendingUpdateReturn,consumeUpdateReturn,prepareUpdatePosition,restoreUpdatePosition,clearUpdateReturn} from './update-return.js';
import {notificationDetailShell,notificationDialogClass} from './notification-detail.js';
import {markStartupReady} from './startup-ready.js';
import {workspaceFrameKey,renderWorkspaceFrame} from './workspace-frame.js';
import {userPortraitMarkup} from './user-portrait.js';
import {observeTables} from './table-presentation.js';
import {workspaceCompany} from './workspace-company.js';
import {customPagesContent,bindCustomPages} from './custom-pages.js';
import {isCustomPageView,customPageForView} from './custom-page-navigation.js';
import {loadCustomPages} from './custom-page-data.js';
import {initialPasswordScreen} from './initial-password.js';
import {setCustomerLanguage} from './customer-language.js';
import {openReportNote} from './report-note-dialog.js';
import './native-language.js';
import {bindTablePageActions} from './table-page-actions.js';
import {customerCan,customerPageVisible} from './customer-access.js';
const permissionStyles=document.createElement('link');permissionStyles.rel='stylesheet';permissionStyles.href='/customer-permissions.css';document.head.append(permissionStyles);
import './static-i18n.js';
import {labelMap as localizedLabels} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {loadPortalIdentity} from './portal-bootstrap.js';
import {openPaymentDetail} from './payment-details.js';
import {bindSettings} from './settings.js';
import {takeSettingsReturn,pendingSettingsReturn,openSettingsAccountPage,bindDesktopNotificationSettings} from './settings-account-page.js';
import {clearMenuSnapshot,stagePagePosition} from './workspace-page-cache.js';
import {bindChatWidget} from './chat-widget.js';
import {settingsButton} from './settings-view.js';
import {applyPreferences,loadPreferences} from './user-preferences.js';
import {confirmPortalExit,bindAndroidBack} from './exit-confirmation.js';
import {bindDateInputs} from './date-inputs.js';
import {pageRefreshButton,bindPageRefresh,markPageUpdated} from './page-refresh.js';
import {openTicket} from './support.js';
import {bindInboxPage,inboxPage,inboxClick} from './inbox.js';
import {updateSupportPage} from './support.js';
import {openUserNotification} from './user-notification.js';
import {calculatorButton,bindCalculator,closeCalculator} from './calculator.js';
import {accountTitle} from './admin-login.js';
import {portalLoginView} from './portal-login-view.js';
import {commitRememberedLogin} from './login-commit.js';
import {completeAdminMfa} from './admin-mfa.js';
import {openOperationNotification} from './operation-notification.js';
import {openOrderNotification} from './order-detail.js';
import {openShipmentNotification} from './shipment-notification.js';
import {marketplaceConnection} from './marketplace-connection.js';
import {editNavigation} from './navigation-order.js';
import {ordersContent} from './orders-view.js';
import {inventoryContent,bindInventory} from './inventory.js';
import {bindOrders} from './orders.js';
import {bindFileCenter} from './file-center.js';
import {bindReturns} from './returns.js';
import {returnsContent} from './returns-view.js';
const returnStyles=document.createElement('link');returnStyles.rel='stylesheet';returnStyles.href='/returns.css';document.head.append(returnStyles);
import {shipmentShareSelection} from './shipment-share.js';
import {shipmentsContent} from './shipments-view.js';
import {bindShipments} from './shipments.js';
import {bindMarketplaceStockList} from './marketplace-stock-list.js';
import {canUseMarketplaceStockPilot} from './marketplace-stock-pilot.js';
import {cityProductsContent,bindCityProducts} from './city-products.js';
import {deliveryContent} from './delivery-view.js';
import {bindDelivery} from './delivery.js';
import {koctasConnection} from './integrations.js';
import {pazaramaConnection} from './pazarama.js';
import {n11Connection} from './n11.js';
import {hepsiburadaConnection} from './hepsiburada.js';
import {koctasCheck} from './koctas-check.js';
import {attachLoginPreferences,saveLoginPreferences} from './login-preferences.js';
import {forgotPassword,resetPasswordFromLink} from './customer-management.js';
import {startLiveRefresh,watchWebVersion} from './live.js';
import {startWorkspaceSync} from './workspace-sync.js';
import {setActionContext} from './action-confirmation.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {api,setCsrf,setOperatorToken} from './api.js';
import {attachLoginIdentity} from './login-identity.js';
import {esc,initials,money,date,now,icon,badge,openDialog,toast} from './ui.js';
import {content} from './views.js';
import {loadSupport,supportContent,supportClick} from './support.js';
import {dashboardQuery,workspaceDateRange,bindWorkspaceDateRange} from './workspace-date-range.js';
import {bindManualDateFilters,enhanceManualDateFilters} from './manual-date-filters.js';
const rangeStyles=document.createElement('link');rangeStyles.rel='stylesheet';rangeStyles.href='/header-date-range.css';
import {workspaceClock,refreshWorkspaceClock} from './workspace-clock.js';
import {bindMarketplaceSales} from './marketplace-summary.js';
import {bindSalesDetails} from './sales-details.js';
import {bindOverviewSections} from './overview-sections.js';
import {preserveTablePosition} from './table-page-scroll.js';
import {openSalesAnalytics} from './sales-analytics.js';
import {recordForm} from './forms.js';
import {clearWorkspace,selectWorkspace,selectCustomerWorkspace,hasWorkspace,workspaceToken,sameWorkspace} from './workspace-context.js';
import {mountCompanyEntry} from './workspace-entry.js';
import {applyCustomerBranding} from './customer-branding.js';
import {customerModuleEnabled,customerPageNames} from './customer-modules.js';
import {customerPage} from './customer-page.js';
import {bindCustomerSelector,customerSelector} from './customer-selector.js';
import {configureCustomerSwitch,transitionCustomer} from './customer-switch.js';
import {bindCustomerActions} from './customer-actions.js';
import {newCustomerSetup} from './customer-setup-new.js';
import {portalNavigation,openMoreMenu} from './portal-navigation.js';
import {adminCanOpenHiddenPage} from './admin-hidden-menus.js';
import {mountWorkspaceNavigation,clientPages,updateRequestMenu,updateQuestionMenu} from './workspace-navigation.js';
import {notificationBell} from './notification-view.js';
import {loadNotifications,bindNotifications,updateNotificationList} from './notifications.js';
import {openSupportResolution} from './support-resolution-notification.js';
import {startOrderAlerts} from './order-alerts.js';
import {initializeBackground,stopBackground} from './background-notifications.js';
import {reportingContent,bindReporting} from './reporting.js';
import {financeReportPages,isFinanceReportPage} from './finance-report-pages.js';
import {bindCustomerWork} from './customer-work.js';
import {isShipmentDocumentPage,shipmentDocumentPages} from './shipment-document-pages.js';
import {customerOverviewMenu,customerOverviewTitle} from './customer-overview-menu.js';
import {createPageMotion} from './motion.js';
import {preparePortalStyles} from './portal-styles.js';
import {bindNavigationPrefetch} from './navigation-prefetch.js';
import {configureManagementAccess} from './marketplace-access.js';
import {loginVisual} from './login-visual.js';
import {bindPortalContentProtection} from './login-content.js';
import {attachAdminLogin} from './admin-login.js';
import {browserLoginUpdateChecker,attachLoginUpdates} from './login-updates.js';
import {attachPasswordVisibility} from './login-visibility.js';
const calendarStyles=document.createElement('link');calendarStyles.rel='stylesheet';calendarStyles.href='/period-calendar.css';document.head.append(calendarStyles);
const calendarPanelStyles=document.createElement('link');calendarPanelStyles.rel='stylesheet';calendarPanelStyles.href='/calendar-panel.css';document.head.append(calendarPanelStyles);
const quickStyles=document.createElement('link');quickStyles.rel='stylesheet';quickStyles.href='/quick-tools.css';document.head.append(quickStyles);
const securityStyles=document.createElement('link');securityStyles.rel='stylesheet';securityStyles.href='/account-security.css';document.head.append(securityStyles);
const passwordStyles=document.createElement('link');passwordStyles.rel='stylesheet';passwordStyles.href='/login-visibility.css';document.head.append(passwordStyles);
const adminLoginStyles=document.createElement('link');adminLoginStyles.rel='stylesheet';adminLoginStyles.href='/admin-login.css';document.head.append(adminLoginStyles);
const detailStyles=document.createElement('link');detailStyles.rel='stylesheet';detailStyles.href='/detail-layout.css';document.head.append(detailStyles);
const settingsStyles=document.createElement('link');settingsStyles.rel='stylesheet';settingsStyles.href='/settings.css';document.head.append(settingsStyles);
const pageBuilderStyles=document.createElement('link');pageBuilderStyles.rel='stylesheet';pageBuilderStyles.href='/application-page-builder.css';pageBuilderStyles.dataset.pageBuilder='';document.head.append(pageBuilderStyles);
const selectorStyles=document.createElement('link');selectorStyles.rel='stylesheet';selectorStyles.href='/customer-selector.css';document.head.append(selectorStyles);
const pageMotion=createPageMotion();
const reportStyles=document.createElement('link');reportStyles.rel='stylesheet';reportStyles.href='/reporting.css';document.head.append(reportStyles);
const motionStyles=document.createElement('link');motionStyles.rel='stylesheet';motionStyles.href='/motion.css';document.head.append(motionStyles);
const workspaceStyles=document.createElement('link');workspaceStyles.rel='stylesheet';workspaceStyles.href='/workspace-entry.css';document.head.append(workspaceStyles);
const summaryStyles=document.createElement('link');summaryStyles.rel='stylesheet';summaryStyles.href='/marketplace-summary.css';document.head.append(summaryStyles);
const monthlyStyles=document.createElement('link');monthlyStyles.rel='stylesheet';monthlyStyles.href='/monthly-sales.css';document.head.append(monthlyStyles);
const salesStyles=document.createElement('link');salesStyles.rel='stylesheet';salesStyles.href='/sales-analytics.css';document.head.append(salesStyles);
const deliveryStyles=document.createElement('link');deliveryStyles.rel='stylesheet';deliveryStyles.href='/delivery.css';document.head.append(deliveryStyles);
const navigationStyles=document.createElement('link');navigationStyles.rel='stylesheet';navigationStyles.href='/navigation-layout.css';document.head.append(navigationStyles);
const brandStyles=document.createElement('link');brandStyles.rel='stylesheet';brandStyles.href='/branding.css';document.head.append(brandStyles);
const shipmentStyles=document.createElement('link');shipmentStyles.rel='stylesheet';shipmentStyles.href='/shipments.css';document.head.append(shipmentStyles);
const orderStyles=document.createElement('link');orderStyles.rel='stylesheet';orderStyles.href='/orders.css?v=0.9.174';document.head.append(orderStyles);
const stockListStyles=document.createElement('link');stockListStyles.rel='stylesheet';stockListStyles.href='/marketplace-stock-list.css';document.head.append(stockListStyles);
const menuOrderStyles=document.createElement('link');menuOrderStyles.rel='stylesheet';menuOrderStyles.href='/navigation-order.css';document.head.append(menuOrderStyles);
const workspaceNavigationStyles=document.createElement('link');workspaceNavigationStyles.rel='stylesheet';workspaceNavigationStyles.href='/workspace-navigation.css';document.head.append(workspaceNavigationStyles);
const presentationStyles=document.createElement('link');presentationStyles.rel='stylesheet';presentationStyles.href='/workspace-presentation.css';document.head.append(presentationStyles);
const clarityStyles=document.createElement('link');clarityStyles.rel='stylesheet';clarityStyles.href='/management-clarity.css';document.head.append(clarityStyles);document.head.append(rangeStyles);
const refinementStyles=document.createElement('link');refinementStyles.rel='stylesheet';refinementStyles.href='/workspace-layout-refinements.css?v=0.9.472';document.head.append(refinementStyles);
const companyStyles=document.createElement('link');companyStyles.rel='stylesheet';companyStyles.href='/workspace-company.css?v=0.9.187';document.head.append(companyStyles);
const reviewStyles=document.createElement('link');reviewStyles.rel='stylesheet';reviewStyles.href='/presentation-review.css?v=0.9.179';document.head.append(reviewStyles);
const controlStyles=document.createElement('link');controlStyles.rel='stylesheet';controlStyles.href='/control-theme.css?v=0.9.187';document.head.append(controlStyles);
const corporateStyles=document.createElement('link');corporateStyles.rel='stylesheet';corporateStyles.href='/corporate-design.css?v=0.9.211';document.head.append(corporateStyles);
observeTables();
const accountDirectoryStyles=document.createElement('link');accountDirectoryStyles.rel='stylesheet';accountDirectoryStyles.href='/account-directory.css?v=0.9.211';document.head.append(accountDirectoryStyles);
const portalStylesReady=preparePortalStyles();
const app=document.getElementById('app');
bindPortalContentProtection(document.body);
bindDateInputs(document.body);bindManualDateFilters(document.body);
function resetPageScroll(){app.scrollTo(0,0);window.scrollTo(0,0);}
import {salesHomePage} from './sales-navigation.js';
const names=localizedLabels({overview:'Genel bakış',customers:'Mağazalar',fileCenter:'Dosya Merkezi',channels:'Pazaryerlerim',inventory:'Stok Yönetimi',marketplaceStocks:'Pazaryeri Stokları',orders:'Siparişler',returns:workspaceMenuDefaultLabel('returns'),analytics:'Analiz ve raporlar',connections:'Bağlantı kontrolü',deliveries:'Şehir Dışı Teslimatlar',shipmentInvoices:shipmentDocumentPages.shipmentInvoices.name,cityProducts:'Şehir Dışı Ürünler',shipments:workspaceMenuDefaultLabel('shipments'),inbox:'Pazaryeri Soruları',payments:'Hakedişlerim',tasks:workspaceMenuDefaultLabel('tasks'),reports:'Raporlarım',support:'Destek talepleri',customPages:'Özel Sayfalar',...Object.fromEntries(Object.entries(financeReportPages).map(([id,page])=>[id,page.label]))});
const state={user:null,companies:[],company:null,month:now().slice(0,7),view:'overview',filter:'all',data:null,demo:false,portalRole:null};
bindDesktopNotificationSettings(state);
bindChatWidget(state);
configureManagementAccess(state);
bindNavigationPrefetch(document,{state,request:path=>api(path),canOpen:view=>customerCan(state,view)});
registerUpdateContext(()=>state);
const portalChannel=location.hostname==='yonetim.omnigoglobal.com'?'admin':location.hostname==='portal.omnigoglobal.com'?'client':null;
const loginUpdates=portalChannel?browserLoginUpdateChecker(portalChannel):null;
document.documentElement.classList.toggle('omnigo-admin-portal',portalChannel==='admin');
document.documentElement.classList.toggle('omnigo-login-updates',Boolean(loginUpdates));
let loadVersion=0;
bindAndroidBack({hasWorkspace:()=>hasWorkspace(state),home:()=>salesHomePage(state),isHome:()=>state.view===salesHomePage(state),navigate});
function loginScreen(setup=false,error='',sessionUser=null){
 window.dispatchEvent(new Event('omnigo-session-ended'));
 setCustomerLanguage(state.portalRole);
 applyPreferences();setOperatorToken('');closeCalculator();clearWorkspace(state);document.body.classList.remove('workspace-entry-open');
 const preserveValues=!setup&&!error&&!state.demo&&app.querySelector('[data-login-initial]')?.dataset.loginRole===state.portalRole&&!app.querySelector('#loginForm')?.dataset.loginBound;
 if(!preserveValues)app.innerHTML=!setup?portalLoginView({role:state.portalRole,error,demo:state.demo}):i18nHtml`<main class="login-layout"><section class="login-story">${loginVisual()}<span class="eyebrow">MARKANIZIN ÇALIŞMA ALANI</span><h1>Sonuçları görün.<br><em>İlerlemeyi takip edin.</em></h1><p>Panel satışlarınız, aktif hakedişleriniz ve devam eden çalışmalar tek bir yerde.</p><div class="login-points"><span>01 / Kanal performansı</span><span>02 / Ödeme takvimi</span><span>03 / Aktif çalışmalar</span></div></section><section class="login-panel"><div><span class="eyebrow">OMNIGO GLOBAL</span><h2>${setup?i18nT('İlk kurulumu tamamlayın'):(state.portalRole==='admin'?i18nT('Yönetici girişi'):i18nT('Müşteri girişi'))}</h2><p>${setup?i18nT('Yönetici hesabınızı oluşturun. Vizyona ve sekiz pazaryeri sizin için hazırlanacak.'):(state.portalRole==='admin'?i18nT('Omnigo yönetici hesabınızla giriş yapın.'):i18nT('Size tanımlanan müşteri hesabıyla giriş yapın.'))}</p>${state.demo?i18nHtml('<div class="demo-note"><strong>ÖRNEK İNCELEME</strong><span>Gerçek müşteri verisi içermez.<br>Yönetici: yonetici@ornek.test<br>Müşteri: musteri@ornek.test</span></div>'):''}<form id="loginForm" class="form-grid">${setup?i18nHtml('<label>Kurulum kodu<input name="setupCode" autocomplete="off" required></label><label>Ad soyad<input name="name" autocomplete="name" required maxlength="100"></label>'):''}<label>E-posta<input name="email" type="email" autocomplete="username" required maxlength="200"></label><label>Şifre<input name="password" type="password" autocomplete="${setup?'new-password':'current-password'}" required ${setup?'minlength="12"':''} maxlength="128"></label>${setup?i18nHtml('<p class="form-help">En az 12 karakter kullanın. Şifrenizi bu ekran üzerinden kendiniz belirleyin.</p>'):''}<p class="form-error" role="alert">${esc(error)}</p><button class="btn primary" type="submit">${setup?i18nT('Yönetici hesabını oluştur'):i18nT('Giriş yap')} ${icon('arrow')}</button></form>${!setup?i18nHtml('<button type="button" class="text-btn" id="forgotPassword">Şifremi unuttum</button>'):''}<small class="login-foot">Omnigo Global · E-Ticaret Danışmanlığı<br><a href="/privacy.html">Gizlilik</a> · <a href="/account-deletion.html">Hesap silme</a> · <a href="/support.html">Destek</a></small></div></section></main>`;
 pageMotion(app.querySelector('.login-panel'),'login');
 if(!preserveValues)resetPageScroll();
 app.querySelector('.login-foot')?.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');
  if(link&&openSettingsAccountPage(link.href,state))event.preventDefault();
 });
 document.getElementById('forgotPassword')?.addEventListener('click',()=>forgotPassword(document.getElementById('loginForm').elements.email.value));
 const form=document.getElementById('loginForm');
 attachPasswordVisibility(form);
 let accountLogin,updateGate;
 if(!setup){
  attachLoginPreferences(form,{compact:true,preserveValues});attachLoginIdentity(form,{preserveValues});
  accountLogin=attachAdminLogin(form,{sessionUser,preserveValues});
  if(loginUpdates)updateGate=attachLoginUpdates(form,loginUpdates);
 }
 form.onsubmit=async e=>{
  e.preventDefault();const submit=form.querySelector('[type=submit]');submit.disabled=true;
  try{
   await updateGate?.beforeLogin();
   const data=Object.fromEntries(new FormData(form));
   if(setup){await api('/setup','POST',data);loginScreen(false);toast(i18nT('Hesabınız hazır. Giriş yapabilirsiniz.'));return;}
   const resume=accountLogin?.resumeAccount();
   let result;
   if(resume){
    try{
     result=await api('/me');
     if(result.user.role!==resume.role||result.user.id!==resume.id||result.user.email!==resume.email){
      throw Object.assign(new Error(i18nT('Oturum değişti. Seçtiğiniz hesabın şifresiyle giriş yapın.')),{status:401});
     }
    }catch(error){if(error.status===401)accountLogin.expireSession();throw error;}
   }else{
    data.keepSession=Boolean(form.elements.keepSession?.checked);delete data.rememberEmail;
    result=await api('/login','POST',data);
    if(result.mfaRequired)result=await completeAdminMfa(result);
   }
   setCsrf(result.csrf);
   state.user=result.user;
   saveLoginPreferences(form);const saved=await commitRememberedLogin(form,accountLogin,result.user);if(saved===false&&form.elements.rememberEmail?.checked)toast(i18nT('Giriş bilgileriniz cihaz kasasına kaydedilemedi. Bir sonraki girişte şifrenizi yeniden yazmanız gerekebilir.'));state.demo=result.demo??state.demo;await initialize();
  }catch(error){form.querySelector('.form-error').textContent=error.message;}
  finally{submit.disabled=false;}
 };
 form.dataset.loginBound='true';window.dispatchEvent(new Event('omnigo-login-bound'));void markStartupReady();
}
async function logout(){if(await app.querySelector('[data-shipments]')?.shipmentCanLeave?.()===false)return;if(!await confirmPortalExit())return;try{await api('/logout','POST',{});try{await stopBackground();}catch{}clearUpdateReturn();state.user=null;setCsrf('');loginScreen();}catch(error){toast(error.message);}}
async function refreshCompanies(){state.companies=await api('/companies');}
function showEntry(nextView='overview'){
 if(typeof nextView!=='string')nextView='overview';
 setActionContext(null);++loadVersion;clearWorkspace(state);document.querySelector('#dialog[open]')?.close();
 mountCompanyEntry(app,{companies:state.companies,onLogout:logout,onSelect:async id=>{
  selectWorkspace(state,id);state.view=nextView;
  try{await load(true);}catch(error){if(error.status===401){state.user=null;setCsrf('');loginScreen(false,error.message);}else clearWorkspace(state);throw error;}
 },onRefresh:async()=>{await refreshCompanies();return state.companies;},onSecurity:href=>openSettingsAccountPage(href,state),onCreate:onCreated=>newCustomerSetup(state,{onCreated,onSave:async()=>{await refreshCompanies();onCreated(state.companies);}})});
 resetPageScroll();void markStartupReady();
}
async function initialize(){if(state.user.mustChangePassword){initialPasswordScreen(app,()=>{state.user=null;setCsrf('');loginScreen(false,i18nT('Şifreniz kaydedildi. Yeni şifrenizle giriş yapın.'));},logout);return;}try{await loadPreferences(state.user);}catch(error){applyPreferences();toast(i18nT('Kişisel görünüm yüklenemedi. Ayarlar bölümünden tekrar deneyebilirsiniz.'));}await refreshCompanies();await initializeBackground(state.user);window.dispatchEvent(new Event('omnigo-session-ready'));const updateReturn=consumeUpdateReturn(state.user,state.companies);if(updateReturn){if(updateReturn.companyId===null){showEntry();void restoreUpdatePosition(state,updateReturn);return;}selectWorkspace(state,updateReturn.companyId);state.view=updateReturn.view;if(updateReturn.month)state.month=updateReturn.month;if(updateReturn.dateRange)state.dateRange=updateReturn.dateRange;state.filter=updateReturn.filter;prepareUpdatePosition(state,updateReturn);await load(true);void restoreUpdatePosition(state,updateReturn);return;}const settingsReturn=takeSettingsReturn(state.user,state.companies);if(settingsReturn){selectWorkspace(state,settingsReturn.companyId);state.view=settingsReturn.view;if(settingsReturn.month)state.month=settingsReturn.month;await load();return;}if(state.user.role==='admin'){if(new URL(location.href).searchParams.get('view')==='connections'){const target=Number(new URL(location.href).searchParams.get('orderCompany'));if(state.companies.some(c=>Number(c.id)===target)){selectWorkspace(state,target);state.view='connections';await load();}else showEntry('connections');history.replaceState(null,'','/');return;}const shared=shipmentShareSelection();if(shared&&state.companies.some(c=>Number(c.id)===shared.company)){selectWorkspace(state,shared.company);state.shipmentSelection=shared;state.view='shipments';await load();history.replaceState(null,'','/');return;}const company=Number(new URL(location.href).searchParams.get('orderCompany'));if(company&&state.companies.some(c=>Number(c.id)===company)){selectWorkspace(state,company);state.view=notificationView({view:new URL(location.href).searchParams.get('view'),type:new URL(location.href).searchParams.get('noticeType')},'admin','orders')||'orders';await load();await openPushDetail({type:new URL(location.href).searchParams.get('noticeType'),noticeId:Number(new URL(location.href).searchParams.get('noticeId'))});history.replaceState(null,'','/');}else showEntry();return;}selectWorkspace(state,state.user.companyId??state.user.company_id??state.companies[0]?.id);state.view=notificationView({view:new URL(location.href).searchParams.get('view'),type:new URL(location.href).searchParams.get('noticeType')},'client','channels')||'channels';await load();const launch=new URL(location.href).searchParams;
 if(launch.has('view')){
  const company=Number(launch.get('orderCompany')),requested=notificationView({view:launch.get('view'),type:launch.get('noticeType')},'client','channels');
  if(requested===state.view&&(!company||company===Number(state.company))&&customerCan(state,state.view))await openPushDetail({type:launch.get('noticeType'),noticeId:Number(launch.get('noticeId'))});
  history.replaceState(null,'','/');
 }
}
async function load(propagate=false){
 if(!state.user||!state.company)return;
 const version=++loadVersion,token=workspaceToken(state);
 try{const [data,customPages]=await Promise.all([api(dashboardQuery(state)),loadCustomPages(api,state.company)]);if(version!==loadVersion||!sameWorkspace(state,token))return;
  data.customPages=customPages;
  if(customPages.unavailable&&!state.customPagesUnavailable)toast(i18nT('Özel sayfalar şu anda yüklenemedi. Diğer bölümleri kullanabilirsiniz.'));
  state.customPagesUnavailable=Boolean(customPages.unavailable);
  applyCustomerBranding(state,app,data.company);state.data=data;render();await Promise.all([loadSupport(state),loadNotifications(state)]);if(version!==loadVersion||!sameWorkspace(state,token))return;updateNotificationList(app,state);if(state.view==='support')updateSupportPage(app,state);
 }catch(error){if(!sameWorkspace(state,token))return;if(propagate)throw error;if(error.status===401){state.user=null;setCsrf('');loginScreen(false,error.message);}else toast(error.message);}
}
let workspaceTransition=false;
configureCustomerSwitch(id=>Number(state.company)===Number(id)?null:changeWorkspace(id,{view:'customers',interactive:true}));
async function changeWorkspace(id,{view=state.view,checkChanges=true,onSelected,interactive=false}={}){
 if(workspaceTransition||state.user?.role!=='admin')return false;
 workspaceTransition=true;
 try{
 const target=state.companies.find(c=>Number(c.id)===Number(id));
 if(!target)return false;
 const token=workspaceToken(state),current=()=>sameWorkspace(state,token);
 const canLeave=async()=>{
  if(checkChanges&&!await canLeaveDialogDrafts({source:state.companies.find(c=>Number(c.id)===Number(state.company))?.name,target:target.name}))return false;
  if(checkChanges&&await app.querySelector('[data-shipments]')?.shipmentCanLeave?.({target:target.name})===false)return false;
  return current();
 };
 const commit=async()=>{try{
  document.querySelector('#dialog[open]')?.close();++loadVersion;
  const company=selectCustomerWorkspace(state,id);state.view=view;setActionContext(company);
  app.innerHTML=i18nHtml('<p class="loading">')+esc(company.name)+i18nHtml(' çalışma alanı yükleniyor…</p>');
  onSelected?.();await load(true);resetPageScroll();return true;
 }catch(error){if(error.status===401){state.user=null;setCsrf('');loginScreen(false,error.message);}else{showEntry(view);toast(error.message);}return false;}};
 if(interactive)return await transitionCustomer(target,{canLeave,switchWorkspace:commit,current});
 if(!await canLeave())return false;
 if(Number(state.company)===Number(id)&&state.view===view)return true;
 return await commit();
 }catch(error){toast(error.message);return false;
 }finally{workspaceTransition=false;}
}
async function navigate(view,{position}={}){
 if(view==='channels')view=salesHomePage(state);
 if(!hasWorkspace(state))return false;
 const allowed=state.user.role==='admin'?Object.keys(names):clientPages;if(!allowed.includes(view)&&!customPageForView(state,view))return false;
 if(state.view==='inbox'&&view!==state.view&&(state.inboxConversation?.draft?.trim()||state.inboxConversation?.files?.length||state.inboxConversation?.sending)){
  toast(i18nT('Ayrılmadan önce yanıtınızı gönderin veya taslağı temizleyin.'));return false;
 }
 if(!await canLeaveDialogDrafts())return false;
 if(await app.querySelector('[data-shipments]')?.shipmentCanLeave?.()===false)return false;
 if(!customerModuleEnabled(state,view)&&view!==state.view&&!adminCanOpenHiddenPage(state,view))return false;
 if(isFinanceReportPage(view)&&state.view===view&&!position&&app.querySelector('[data-reporting]')?.dataset.reporting===view)return true;
 const token=workspaceToken(state);
 if(state.view!==view){
  if(view==='overview')state.overviewSections=null;
  if(view==='inbox')Object.assign(state,{inboxPage:0,inboxStatus:'waiting',inboxUnreadOnly:false,inboxConversation:null,inboxOpening:null,inboxFiltersOpen:false,inboxDownloading:false,inboxProvider:'',inboxSearch:'',inboxData:null,inboxError:''});
  if(view!=='shipmentInvoices'&&isShipmentDocumentPage(view))clearMenuSnapshot(state,view);
 }
 if(position)stagePagePosition({...state,view},position);
 state.view=view;
 try{
  if(view==='support')state.supportPage=0;
  if(!sameWorkspace(state,token)||state.view!==view)return false;render({menuTransition:true});resetPageScroll();if(view==='support')void loadSupport(state).then(()=>{if(sameWorkspace(state,token)&&state.view===view)updateSupportPage(app,state);}).catch(error=>{if(sameWorkspace(state,token))toast(error.message);});return true;
 }catch(error){toast(error.message);return false;}
}
function render(options){
 setCustomerLanguage(state.user?.role??state.portalRole);
 if(!state.user)return;
 if(!hasWorkspace(state)){if(state.user.role==='admin')showEntry();return;}
 document.body.classList.remove('workspace-entry-open');
 const company=state.companies.find(c=>c.id===Number(state.company)),admin=state.user.role==='admin';
 setActionContext(company);
 if(admin&&state.view==='overviewWork'){
  state.view='overview';
  state.overviewSections={company:state.company,tab:'work'};
 }
 const customPage=customPageForView(state);
 if(admin&&((!Object.hasOwn(names,state.view)&&!customPage)||(state.view==='marketplaceStocks'&&!canUseMarketplaceStockPilot(state))))state.view='overview';
 const customPageAvailable=Boolean(customPage);
 if(!admin&&((!clientPages.includes(state.view)&&!customPageAvailable)||!customerModuleEnabled(state,state.view)))state.view=clientPages.find(page=>customerModuleEnabled(state,page))||'channels';
 if(state.view==='channels')state.view=salesHomePage(state);
 const moduleEnabled=isCustomPageView(state.view)?customPageAvailable:customerModuleEnabled(state,state.view)||adminCanOpenHiddenPage(state,state.view);
 const overviewNames=Object.fromEntries(Object.entries(customerOverviewMenu).map(([id,entry])=>[id,i18nT(entry.label)]));
 const visibleNames=customerPageNames(admin?names:{fileCenter:i18nT('Dosya Merkezi'),financeProfitability:i18nT('Kârlılık Analizi'),overview:i18nT(customerOverviewTitle),...overviewNames,inventory:i18nT('Stok Yönetimi'),orders:i18nT('Siparişler'),returns:i18nT(workspaceMenuDefaultLabel('returns')),channels:i18nT('Panel satışları'),analytics:i18nT('Analiz ve raporlar'),tasks:i18nT(workspaceMenuDefaultLabel('tasks')),payments:i18nT('Aktif hakedişler'),deliveries:i18nT('Şehir Dışı Teslimatlar'),shipmentInvoices:i18nT(shipmentDocumentPages.shipmentInvoices.name),cityProducts:i18nT('Şehir Dışı Ürünler'),shipments:i18nT(workspaceMenuDefaultLabel('shipments')),reports:i18nT('Raporlarım'),support:i18nT('Destek taleplerim'),customPages:i18nT('Özel Sayfalar')},state);
 delete visibleNames.customPages;
 const nav=(mobile=false)=>portalNavigation(visibleNames,state,{mobile});
 const intro={...Object.fromEntries(Object.entries(customerOverviewMenu).map(([id,entry])=>[id,i18nT(entry.description)])),inventory:i18nT('Pazaryeri ve mağaza stokları, ürün hareketleri ve satış dağılımı.'),returns:i18nT('Pazaryerlerinden aktarılan iptal ve iade talepleri.'),orders:i18nT('Bağlı pazaryerlerinden aktarılan siparişler. Yeni kayıtlar otomatik olarak bu listede görünür.'),shipments:i18nT('Pazaryeri ve aya göre tüm siparişler. Teslimat şehrini üstteki seçimden filtreleyin.'),deliveries:admin?i18nT('Şehre göre aktif siparişleri seçin, sevkiyat evraklarını hazırlayın.'):i18nT('Firmanızın teslimat listesini ve güncel sipariş durumlarını takip edin.'),analytics:i18nT('Satışların gelişimi, önceki dönem karşılaştırması ve indirilebilir raporlar.'),connections:i18nT('Seçili müşterinin pazaryeri aktarım ve bağlantı durumu.'),customers:i18nT('Mağaza bilgileri, kullanıcı hesapları ve erişim ayarları.'),support:admin?i18nT('Seçili müşterinin soruları ve destek talepleri.'):i18nT('Sorularınız ve Omnigo ekibiyle görüşmeleriniz.'),overview:i18nT('Satışlar, ödemeler ve çalışmaların güncel özeti.'),channels:i18nT('Kanal bazında mağaza durumu ve satış dağılımı.'),shipmentInvoices:i18nT('Şehir Dışı Sevkiyat siparişlerinin bekleyen ve yüklenen faturaları.'),cityProducts:i18nT('Teslimat iline göre sipariş edilen ürünler.'),tasks:i18nT('Ekibin yürüttüğü çalışmalar ve sonraki adımlar.'),reports:i18nT('Dönem değerlendirmeleri ve markanız için hazırlanan raporlar.'),customPages:i18nT('Yetkili yönetici tarafından yayımlanan kurumsal çalışma sayfaları.')};
 if(customPage&&isCustomPageView(state.view))intro[state.view]=customPage.summary||'';
 const headingText=i18nHtml`<span class="eyebrow">${admin?i18nT('YÖNETİCİ GÖRÜNÜMÜ'):i18nT('MÜŞTERİ GÖRÜNÜMÜ')}</span><h1>${workspaceMenuLabelMarkup(state,state.view,state.view==='inbox'?i18nT('Pazaryeri Soruları'):(visibleNames[state.view]||names[state.view]))}</h1>${intro[state.view]?i18nHtml`<p>${intro[state.view]}</p>`:''}`;
 const pageHeading=i18nHtml`<div class="page-heading${state.view==='customers'?' customers-page-heading':''}">${workspaceTableHeading(state,headingText,moduleEnabled)}<div class="page-heading-actions">${state.view==='customers'?customerSelector(state):workspaceDateRange(state)}</div></div>`;
 const crumbMarkup=i18nHtml`${admin?i18nT('Yönetici Portalı'):i18nT('İş Ortağım')} / <strong>${workspaceMenuLabelMarkup(state,state.view,state.view==='inbox'?i18nT('Pazaryeri Soruları'):(visibleNames[state.view]||names[state.view]))}</strong>`;
 const mainMarkup=i18nHtml`<main class="main">${state.demo?i18nHtml('<div class="demo-note"><strong>ÖRNEK VERİLER</strong><span>Bu inceleme alanı gerçek Vizyona satışlarını içermez. Değişiklikler ayrı örnek veritabanına kaydedilir.</span></div>'):''}${pageHeading}${customerCan(state,state.view,'create')&&['tasks','reports'].includes(state.view)?i18nHtml`<div class="admin-toolbar"><button class="btn primary" data-add="${state.view}">${icon('plus')} ${state.view==='tasks'?i18nT('Çalışma ekle'):state.view==='reports'?i18nT('Rapor hazırla'):i18nT('Hakediş ekle')}</button></div>`:''}${!moduleEnabled?i18nHtml('<section class="empty-state"><h2>Bu bölüm seçili müşteride etkin değil</h2><p>Bölüm görünürlüğü ve kullanıcı yetkileri yönetici tarafından Müşteriler sayfasından düzenlenir.</p></section>'):(state.view==='customPages'||isCustomPageView(state.view))?customPagesContent(state):state.view==='returns'?returnsContent():state.view==='marketplaceStocks'?'<section class="orders-workspace" data-stock-listings></section>':state.view==='inventory'?inventoryContent():state.view==='orders'?ordersContent():state.view==='shipments'?shipmentsContent():state.view==='deliveries'?deliveryContent(state):state.view==='cityProducts'?cityProductsContent(state):(['analytics','payments','connections','overviewWork'].includes(state.view)||isShipmentDocumentPage(state.view)||isFinanceReportPage(state.view))?reportingContent(state):admin&&state.view==='customers'?customerPage(state):state.view==='support'?supportContent(state):state.view==='inbox'&&admin?inboxPage(state):content(state)}<p id="connectionStatus" class="connection-status" role="status" hidden></p><div class="footer-note"><span>Omnigo Global · E-Ticaret Danışmanlığı</span></div></main>`;
 const workspaceMarkup=()=>i18nHtml`<aside class="sidebar"><div class="brand"><img src="assets/omnigo-logo.png" alt="Omnigo Global" width="190" height="107"><span>${admin?i18nT('YÖNETİCİ PORTALI'):i18nT('İŞ ORTAĞIM')}</span></div><div class="nav-label">Çalışma alanınız</div><nav class="nav" aria-label="Ana menü">${nav()}</nav><div class="sidebar-bottom"><div class="support-label">Sizinle çalışan ekip</div><div class="team"><span class="avatar">OG</span><div><strong>Omnigo Global</strong><small>Operasyon & raporlama</small></div></div></div></aside><div class="workspace workspace-headers-polished ${state.view==='shipments'?'':'workspace-polished'}"><header class="topbar"><span class="crumb">${crumbMarkup}</span><div class="mobile-brand"><img src="assets/omnigo-logo.png" alt="Omnigo Global" width="124" height="70"></div>${admin?'':workspaceCompany(company)}<div class="top-actions">${workspaceClock()}${calculatorButton()}${notificationBell(state)}${settingsButton()}<div class="account"><span class="avatar">${userPortraitMarkup(state.user,{companyName:company?.name,fallbackName:admin?state.user.name:(Array.from(String(state.user.name||'').trim())[0]?.toLocaleUpperCase('tr-TR')||'?')})}</span><div>${esc(state.user.name)}<small>${esc(accountTitle(state.user))}</small></div></div><button class="logout" id="logout" aria-label="Çıkış yap">${icon('logout')}</button></div></header>${mainMarkup}</div><nav class="mobile-nav" aria-label="Mobil menü">${nav(true)}</nav>`;
 const reusedFrame=renderWorkspaceFrame(app,{full:workspaceMarkup,main:mainMarkup,crumb:crumbMarkup,view:state.view},workspaceFrameKey(state,company,visibleNames,document.documentElement.lang));
 app.querySelector('.sidebar')?.classList.add(admin?'admin-sidebar':'client-sidebar');
 const refreshWorkspaceNavigation=mountWorkspaceNavigation(app,visibleNames,state,{reuseStructure:reusedFrame&&options?.menuTransition===true});

 document.getElementById('logout').onclick=logout;
 bindNotifications(app,state,render);
 refreshWorkspaceClock();bindCalculator(app);enhanceManualDateFilters(app);bindWorkspaceDateRange(app,state,load);
 bindSalesDetails(app,state);bindOverviewSections(app,state,{navigate,render});
 bindMarketplaceSales(app,state);
 bindCustomerSelector(app,state,id=>changeWorkspace(id,{view:'customers',interactive:true}));
 bindReporting(app,state,{navigate});
 bindCustomerWork(app,state,{navigate});
 bindDelivery(app,state);
 bindShipments(app,state,{navigate});
 bindOrders(app,state);
 bindFileCenter(app,state);
 bindInventory(app,state);
 bindMarketplaceStockList(app,state);
 bindCityProducts(app,state);
 bindReturns(app,state);
 bindInboxPage(app,state);
 bindCustomPages(app,state,render);
 bindTablePageActions(app,state);
 const refreshView=state.view,refreshToken=workspaceToken(state);
 if(!isFinanceReportPage(state.view)&&!['shipments','deliveries','cityProducts','orders','returns','inventory','marketplaceStocks'].includes(state.view))markPageUpdated();
 bindPageRefresh(app,{refresh:()=>refreshWorkspace({manual:true}),current:()=>sameWorkspace(state,refreshToken)&&state.view===refreshView,notify:toast});
 if(admin)bindCustomerActions(app,state,{
  onOpenStockList:()=>navigate('marketplaceStocks'),
  onAccountUpdated:async()=>{const currentUser=state.user;try{const result=await api('/me');if(state.user===currentUser){Object.assign(currentUser,result.user);render();}}catch(error){if(error.status!==401)throw error;state.user=null;setCsrf('');loginScreen(false,i18nT('Hesap bilgileriniz güncellendi. Yeniden giriş yapın.'));}},
  onSave:async()=>{await refreshCompanies();await load();},onSwitch:showEntry});
 const bindEdit=()=>{const button=app.querySelector('[data-edit-navigation]');if(button)button.onclick=()=>editNavigation(names,state,refreshNavigation);};
 const refreshNavigation=()=>{const sidebarNav=app.querySelector('.sidebar .nav');if(sidebarNav)sidebarNav.innerHTML=nav();refreshWorkspaceNavigation();app.querySelector('.mobile-nav').innerHTML=nav(true);bindMore();bindEdit();};
 bindSettings(app,state,{
  onOpenStockList:()=>navigate('marketplaceStocks'),
  onNavigation:()=>editNavigation(names,state,refreshNavigation),
  onNavigatePage:view=>navigate(view),
  onCustomPagesChanged:()=>load(),
  beforeLanguageChange:()=>app.querySelector('[data-shipments]')?.shipmentCanLeave?.(),
  onLanguageChanged:()=>render(),
  onSessionEnded:async()=>{try{await stopBackground();}catch{}state.user=null;setCsrf('');loginScreen(false,i18nT('Oturumunuz kapatıldı.'));},
  onPasswordChanged:async()=>{try{await stopBackground();}catch{}state.user=null;setCsrf('');loginScreen(false,i18nT('Şifreniz değişti. Yeni şifrenizle giriş yapın.'));},
 });
 const bindMore=()=>{const button=app.querySelector('[data-more-menu]');if(button)button.onclick=()=>openMoreMenu(admin?names:visibleNames,state,navigate,refreshNavigation);};bindMore();
 bindEdit();void markStartupReady();
}
app.addEventListener('click',async event=>{const el=event.target.closest('button');if(!el||!hasWorkspace(state))return;
 if(el.dataset.connect==='koctas'&&state.user.role==='admin')koctasConnection(state,load);
 if(el.dataset.connect==='pazarama'&&state.user.role==='admin')pazaramaConnection(state,load);
 if(el.dataset.connect==='n11'&&state.user.role==='admin')n11Connection(state,load);
 if(['pttavm','idefix','trendyol'].includes(el.dataset.connect)&&state.user.role==='admin')marketplaceConnection(state,el.dataset.connect);
 if(el.dataset.connect==='hepsiburada'&&state.user.role==='admin')hepsiburadaConnection(state,load);
 if(el.dataset.connect==='koctas-check'&&state.user.role==='admin')koctasCheck(state);
 if(el.dataset.inbox||el.dataset.inboxThread){await inboxClick(el,state,()=>app.querySelector('[data-marketplace-questions]')?.inboxPaint?.());return;}
 if(el.dataset.support||el.dataset.supportTicket){await supportClick(el,state,render);return;}
 if(el.dataset.nav){await navigate(el.dataset.nav);return;}
 if(el.dataset.salesChannel){await openSalesAnalytics(state,el.dataset.salesChannel,{products:!el.hasAttribute('data-sales-only')});return;}
 if(el.dataset.filter){state.filter=el.dataset.filter;render();}
 if(el.dataset.add&&customerCan(state,el.dataset.add,'create'))recordForm(el.dataset.add,null,state,load);
 if(el.dataset.edit&&customerCan(state,el.dataset.edit,'edit'))recordForm(el.dataset.edit,state.data[el.dataset.edit].find(r=>r.id===Number(el.dataset.id)),state,load);
 if(el.dataset.payment){const p=state.data.payments.find(r=>r.id===Number(el.dataset.payment));if(p)openPaymentDetail(p,{state,onSave:load});}
 if(el.dataset.report)openReportNote(state,el.dataset.report);
});
try{
 const [{status,identity}]=await Promise.all([loadPortalIdentity(api),portalStylesReady]);state.demo=status.demo;state.portalRole=status.portalRole??null;
 if(status.needsSetup)loginScreen(true);
 else{
  try{
   const me=identity;
   if(!me)loginScreen();
   else if(pendingUpdateReturn(me.user)||pendingSettingsReturn(me.user)){state.user=me.user;setCsrf(me.csrf);await initialize();}
   else if(me.user.role==='admin'||me.user.role==='client'||loginUpdates)loginScreen(false,'',me.user);
   else{state.user=me.user;setCsrf(me.csrf);await initialize();}
  }catch(error){if(error.status===401)loginScreen();else throw error;}
 }
}catch(error){document.documentElement.dataset.omnigoApplicationFailed='true';app.innerHTML=i18nHtml`<main class="empty-state"><h1>Portal şu anda açılamıyor</h1><p>${esc(error.message)}</p><p>Bağlantınızı kontrol edip sayfayı yenileyin.</p></main>`;void markStartupReady();}

let workspaceRefreshing=null;
async function refreshWorkspace({manual=false}={}){
 if(workspaceRefreshing){if(!manual)return workspaceRefreshing;await workspaceRefreshing.catch(()=>{});}
 workspaceRefreshing=refreshWorkspaceData({manual}).finally(()=>{workspaceRefreshing=null;});return workspaceRefreshing;
}
async function refreshWorkspaceData({manual=false}={}){
 const company=state.company,month=state.month,view=state.view,token=workspaceToken(state),rangeQuery=dashboardQuery(state);
 const attentionRoot=app.querySelector('[data-attention-center]');
 const activeAttention=attentionRoot&&!attentionRoot.closest('[role=tabpanel]')?.hidden;
 const workRoot=app.querySelector('[data-customer-work]'),workTab=workRoot?.closest('[role=tabpanel]');
 const activeWork=workTab&&!workTab.hidden;
 const fileCenter=app.querySelector('[data-file-center]');
 const pageRoot=activeAttention?attentionRoot:app.querySelector('[data-reporting],[data-delivery],[data-city-products],[data-shipments],[data-orders],[data-returns],[data-inventory],[data-stock-listings],[data-marketplace-questions]')||fileCenter||(!workTab||activeWork?workRoot:null);
 if(manual&&document.querySelector('#dialog[open]'))throw new Error(i18nT('Önce açık pencereyi tamamlayın veya kapatın.'));
 if(manual&&pageRoot?.pageRefresh)return pageRoot.pageRefresh();
 try{
  const data=await api(rangeQuery);
  if(!sameWorkspace(state,token)||month!==state.month||view!==state.view||rangeQuery!==dashboardQuery(state))return;
  applyCustomerBranding(state,app,data.company);
  if(JSON.stringify(data.workspaceAccess)!==JSON.stringify(state.data?.workspaceAccess)){
   const inUse=document.querySelector('#dialog[open]')||app.querySelector('[data-shipment-editor],[data-shipment-session-save]');
   if(!inUse){state.data=data;render();}
   // Wait for the current form to close so its permissions are rebound on the next refresh.
   return;
  }
  const before=JSON.stringify([state.supportData,state.supportError]);
  const readingHistory=state.notificationPage>0&&app.querySelector('.notification-bell[aria-expanded="true"]');
  await Promise.all([loadSupport(state),readingHistory?Promise.resolve():loadNotifications(state)]);if(!sameWorkspace(state,token)||month!==state.month||view!==state.view||rangeQuery!==dashboardQuery(state))return;
  if(manual&&view==='customers')await refreshCompanies();
  if(manual&&state.supportError&&view==='support')throw new Error(state.supportError);
  if(!sameWorkspace(state,token)||month!==state.month||view!==state.view||rangeQuery!==dashboardQuery(state))return;
  updateNotificationList(app,state);
  updateRequestMenu(app,data.marketplaceRequestsPending);
  if(Number.isSafeInteger(data.marketplaceQuestionsPending)){
   state.data.marketplaceQuestionsPending=data.marketplaceQuestionsPending;
   updateQuestionMenu(app,data.marketplaceQuestionsPending);
  }
  // Keep the active operations table and its pager alive during dashboard polling.
  // Other overview panels render the newly received data when selected.
 if(attentionRoot?.isConnected&&!attentionRoot.closest('[role=tabpanel]')?.hidden){state.data=data;attentionRoot.closest('[data-overview-sections]').dataset.refreshPending='true';await attentionRoot.attentionRefresh?.();return;}
 if(workTab?.isConnected&&!workTab.hidden){state.data=data;workTab.closest('[data-overview-sections]').dataset.refreshPending='true';await workRoot.workRefresh?.();return;}
  if(!fileCenter&&!app.querySelector('[data-reporting],[data-delivery],[data-city-products],[data-shipments],[data-orders],[data-returns],[data-inventory],[data-stock-listings],[data-marketplace-questions]')
   &&(manual||JSON.stringify(data)!==JSON.stringify(state.data)||before!==JSON.stringify([state.supportData,state.supportError]))
   &&!app.querySelector('.notification-bell[aria-expanded="true"],.workspace-date-range:focus-within,[data-customer-work]:focus-within')
   &&view!=='overviewWork'){
   const restorePosition=preserveTablePosition(app);
   state.data=data;
   render();
   restorePosition();
  }
  const returnsRoot=app.querySelector('[data-returns]');if(returnsRoot)await returnsRoot.returnsRefresh?.();
  const stockListingsRoot=app.querySelector('[data-stock-listings]');if(stockListingsRoot)await stockListingsRoot.stockListingsRefresh?.();
  const inventoryRoot=app.querySelector('[data-inventory]');if(inventoryRoot)await inventoryRoot.inventoryRefresh?.();
  const ordersRoot=app.querySelector('[data-orders]');if(ordersRoot)await ordersRoot.ordersRefresh?.();
  if(fileCenter?.isConnected)await fileCenter.fileCenterRefresh?.();
  const shipmentRoot=app.querySelector('[data-shipments]');if(shipmentRoot)await shipmentRoot.shipmentRefresh?.();
  const cityProductsRoot=app.querySelector('[data-city-products]');if(cityProductsRoot){state.data=data;await cityProductsRoot.cityProductsRefresh?.({background:true});}
  const deliveryRoot=app.querySelector('[data-delivery]');if(deliveryRoot){state.data=data;await deliveryRoot.deliveryRefresh?.();}
  const inboxRoot=app.querySelector('[data-marketplace-questions]');if(inboxRoot)await inboxRoot.inboxRefresh?.();
  const reportRoot=app.querySelector('[data-reporting]');if(reportRoot&&!reportRoot.exportBusy){state.data=data;await reportRoot.reportRefresh?.();}
 if(workRoot?.isConnected&&workRoot===pageRoot)await workRoot.workRefresh?.();
 }catch(error){if(error.status===401){state.user=null;setCsrf('');loginScreen(false,error.message);}throw error;}
}
startLiveRefresh({isSignedIn:()=>hasWorkspace(state),notify:toast,refresh:refreshWorkspace});
startWorkspaceSync({state,isSignedIn:()=>hasWorkspace(state),refresh:refreshWorkspace,onIdentity:value=>applyCustomerBranding(state,app,value)});
startOrderAlerts(state,async()=>{if(state.company){await loadNotifications(state);updateNotificationList(app,state);}});
async function openPushDetail(detail){
 const id=Number(detail?.noticeId);if(!Number.isSafeInteger(id)||id<=0)return;
 const token=workspaceToken(state);
 if(state.user?.role==='client'){
  if(detail.type==='order')await openOrderNotification(state,id);
  else if(detail.type==='operation')await openOperationNotification({id},state,token);
  else if(['user','resolved'].includes(detail.type))await openSupportResolution({id},state,token,render);
  else if(detail.type==='support')await openTicket(id,state,render);
  await loadNotifications(state);updateNotificationList(app,state);return;
 }
 if(detail.type==='shipment')await openShipmentNotification({id},state,token);
 if(detail.type==='order')await openOrderNotification(state,id);
 if(detail.type==='operation')await openOperationNotification({id},state,token);
 if(detail.type==='support')await openTicket(id,state,render);
 if(detail.type==='user')await openUserNotification({id},state,token,render);
 await loadNotifications(state);updateNotificationList(app,state);
}
window.addEventListener('omnigo-order-open',async event=>{
 if(state.user?.role==='client'){
  if(!['user','resolved','support','order','operation'].includes(event.detail?.type)||Number(event.detail?.companyId)!==Number(state.user.companyId??state.user.company_id))return;
  try{const page=notificationView(event.detail,'client');if(!page)return;if(hasWorkspace(state)&&customerCan(state,page)&&hasNotificationDetail(event.detail)){if(!await canLeaveDialogDrafts())return;document.querySelector('#dialog[open]')?.close();await openPushDetail(event.detail);}else if(await navigate(page))await openPushDetail(event.detail);}catch(error){toast(error.message);}return;
 }
 if(state.user?.role!=='admin')return;
 const company=Number(event.detail?.companyId);if(!state.companies.some(item=>Number(item.id)===company))return;
 try{
  const type=event.detail?.type,view=notificationView(event.detail,'admin');if(!view)return;
  if(hasWorkspace(state)&&Number(state.company)===company&&type!=='integration'){if(!hasNotificationDetail(event.detail)){await navigate(view);return;}if(!await canLeaveDialogDrafts())return;document.querySelector('#dialog[open]')?.close();await openPushDetail(event.detail);return;}
  let pending;const className=notificationDialogClass(type);
  if(!await changeWorkspace(company,{view,onSelected:()=>{if(className)pending=notificationDetailShell(state,workspaceToken(state),i18nT('Bildirim ayrıntıları'),className);}}))return;
  if(Number(state.company)!==company||state.view!==view||pending&&!pending.active())return;
  if(type!=='integration')await openPushDetail(event.detail);
 }catch(error){toast(error.message);}
});
watchWebVersion();
window.addEventListener('omnigo-push-changed',()=>{if(hasWorkspace(state))bindNotifications(app,state,render);});
navigator.serviceWorker?.addEventListener('message',event=>{if(event.data?.type==='omnigo-order-open')window.dispatchEvent(new CustomEvent('omnigo-order-open',{detail:{companyId:event.data.companyId,type:event.data.eventType,view:event.data.view,noticeId:event.data.noticeId}}));});
resetPasswordFromLink();



