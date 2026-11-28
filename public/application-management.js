import {api} from './api.js';
import {html,t} from './i18n.js';
import {workspaceDesignSettings,bindWorkspaceDesignSettings} from './workspace-design-settings.js';

export const managementModules=[
 ['design','Tasarım ve marka','Ortak uygulama düzeni, renk paleti, yüzeyler ve marka bütünlüğü.','design','Ortak tasarımı düzenle'],
 ['customers','Mağazalar, kullanıcılar ve API','Mağaza bilgileri, pazaryeri bağlantıları, kullanıcı hesapları ve yetkiler.','customers','Mağaza, kullanıcı ve API ayarlarını aç'],
 ['notifications','Bildirimler','Yönetim adresine gönderilen operasyon e-postaları.','notifications','Bildirim tercihlerini düzenle'],
];

function moduleCard([id,title,description,target,actionLabel],index){
 return html`<button type="button" class="management-module-card" data-management-action="${target}" data-management-module="${id}" aria-label="${t(actionLabel)}"><span class="management-module-number">${String(index+1).padStart(2,'0')}</span><span><strong>${t(title)}</strong><small>${t(description)}</small><em>${t(actionLabel)}</em></span><span aria-hidden="true">›</span></button>`;
}

export function managementHome(){
 return html`<section class="management-home" aria-label="Uygulama Yönetimi">
 <header class="management-hero"><div><span class="management-kicker">Yalnız yetkili yönetici</span><h3>Uygulama Yönetimi</h3><p>Ortak uygulama kurallarını tek merkezden yönetin. Her alanın kapsamı ve etkisi işlemden önce açıklanır.</p></div><span class="management-owner-badge">Kurumsal yönetim</span></header>
 <div class="management-module-grid">${managementModules.map(moduleCard).join('')}</div>
 <section class="settings-account-card management-panel" data-management-panel="design"><div class="management-panel-heading"><span>01</span><div><h3>Ortak tasarım ve çalışma alanı</h3><p>Seçim mevcut ve yeni hesaplara uygulanır; mağazaya özel marka ve veriler korunur.</p></div></div>${workspaceDesignSettings()}<details><summary>Bu alan neyi değiştirir?</summary><p>Portalın ortak yerleşimini, yüzeyini ve renk sistemini belirler. Canlı aktarım, yetki, müşteri verisi ve mağaza bağlantılarına dokunmaz.</p></details></section>
 <section class="settings-account-card management-panel" data-management-panel="notifications"><div class="management-panel-heading"><span>03</span><div><h3>Bildirim tercihleri</h3><p>Yönetim adresine gönderilen operasyon e-postalarını yönetin.</p></div></div><form data-operation-mail><label class="settings-field"><span>Yönetim adresine operasyon e-postaları</span><select name="enabled" disabled aria-describedby="operation-mail-scope"><option value="true">Açık</option><option value="false">Kapalı</option></select><small id="operation-mail-scope">Tüm mağazalardaki yeni sipariş, kesinleşen iptal, iptal talebi, iade durumu, fatura veya belge yükleme, kullanıcı işlemi ve bildirim denemelerini kapsar. Güvenlik ve aktarım kesintisi e-postaları kendi kurallarına göre gönderilir.</small></label><div class="settings-actions"><button class="btn" type="submit" disabled>Kaydet</button></div><p role="status"></p></form></section>
 </section>`;
}

function scrollToPanel(panel,name){
 const target=panel.querySelector(`[data-management-panel="${name}"]`);
 target?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
 target?.querySelector('button,select,a')?.focus({preventScroll:true});
}

export function bindManagementHome(panel,{drafts,current,openCustomers}){
 for(const button of panel.querySelectorAll('[data-management-action]')){
  button.onclick=()=>{
   if(!current())return;
   const action=button.dataset.managementAction;
   if(action==='design'||action==='notifications')scrollToPanel(panel,action);
   else if(action==='customers')openCustomers?.();
  };
 }
 const form=panel.querySelector('[data-operation-mail]');
 const feedback=form.querySelector('[role=status]');
 let version=0;
 async function loadMail(){
  feedback.textContent=t('Bildirim tercihi yükleniyor…');
  try{
   const result=await api('/account/operation-mail');
   if(!current()||!form.isConnected)return;
   feedback.textContent='';
   version=result.version;form.elements.enabled.value=String(result.enabled);form.elements.enabled.disabled=false;
   drafts.track(form,{label:t('Operasyon e-postaları'),save:async values=>{
    try{const saved=await api('/account/operation-mail','PUT',{enabled:values.enabled==='true',version});version=saved.version;feedback.textContent=t('Bildirim tercihi kaydedildi.');return true;}
    catch(error){feedback.textContent=error.message;return false;}
   }});
  }catch(error){if(current())feedback.textContent=error.message;}
 }
 void loadMail();
 return bindWorkspaceDesignSettings(panel.querySelector('[data-workspace-design-form]'),{drafts,current});
}
