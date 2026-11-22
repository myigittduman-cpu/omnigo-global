import {shippingScopeFields,bindShippingScope} from './shipping-scope.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api,upload} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {managementCompanySelector,bindManagementCompany} from './customer-management-selector.js';
import {ensureMarketplaceAccess,finishAccessCover} from './marketplace-access.js';
import {protectManagementDialog} from './management-access-flow.js';
import {bindCustomerMenuLabelEditor} from './customer-menu-label-editor.js';

const input=(label,name,value='',type='text',max=200)=>i18nHtml`<label>${label}<input name="${name}" type="${type}" value="${esc(value)}" maxlength="${max}"></label>`;
const message=i18nHtml('<p class="form-error full-width" role="alert"></p>');
function bindForm(form,action){
 form.onsubmit=async event=>{event.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;try{await action(Object.fromEntries(new FormData(form)));}catch(error){form.querySelector('.form-error').textContent=error.message;}finally{button.disabled=false;}};
}
import {companyWordmarkClass} from './user-portrait.js';

export async function customerManagement(state,onSave,selectedTab='profile',companyId=null){
 if(state.user?.role!=='admin')return;
 try{
  const token=workspaceToken(state);
  const current=()=>sameWorkspace(state,token);
  const reopen=(tab=selectedTab)=>customerManagement(state,onSave,tab,companyId);
  if(companyId===null){
   const chooser=openDialog(i18nT('Firma ayarları'),managementCompanySelector(state.companies),{className:'customer-settings-dialog management-company-chooser'});
   bindManagementCompany(chooser,state.companies,null,{current,onSelect:id=>customerManagement(state,onSave,selectedTab,id)});
   return;
  }
  const company=state.companies.find(c=>Number(c.id)===Number(companyId));
  if(!company)throw new Error(i18nT('Mağaza seçin'));
  companyId=Number(company.id);
  if(!await ensureMarketplaceAccess(state,company)||!current())return;
  const endpoint=path=>`/management/${path}?company=${company.id}`;
  const data=await api(endpoint('customer')),p=data.profile;
  if(!sameWorkspace(state,token))return;
  const dialog=openDialog(i18nText`${company.name} · Firma ayarları`,i18nHtml`
   ${managementCompanySelector(state.companies,companyId)}
   <div class="management-tabs" role="tablist" aria-label="Mağaza ayarları">
    <button class="btn" role="tab" aria-selected="true" data-tab="profile">Firma bilgileri</button><button class="btn" role="tab" aria-selected="false" data-tab="logo">Mağaza logosu</button>
    <button class="btn" role="tab" aria-selected="false" data-tab="mail">E-posta işlemleri</button><button class="btn" role="tab" aria-selected="false" data-tab="shipping">Sevkiyat kapsamı</button>
    <button class="btn" role="tab" aria-selected="false" aria-controls="customerMenuLabels" id="customerMenuLabelsTab" data-tab="menu-labels">Menü adları</button>
   </div>
   <section data-section="profile"><form class="form-grid" id="customerProfile">
    ${input(i18nT('Firma adı'),'name',company.name,'text',150)}${input(i18nT('Yetkili kişi'),'contactName',p.contactName,'text',100)}
    ${input(i18nT('Telefon'),'phone',p.phone,'tel',40)}${input(i18nT('İletişim e-postası'),'email',p.email,'email')}
    <label>Adres<textarea name="address" maxlength="500" rows="2" autocomplete="street-address">${esc(p.address)}</textarea></label>
    <label class="full-width">Yalnızca yöneticinin göreceği notlar<textarea name="notes" maxlength="3000" rows="3">${esc(p.notes)}</textarea></label>
    ${message}<button type="submit" class="btn primary">Bilgileri kaydet</button></form>
    </section><section data-section="logo" hidden><h3>Mağaza logosu</h3>${p.hasLogo?i18nHtml`<img class="customer-logo-preview${companyWordmarkClass(company.name)}" src="/api/management/logo?company=${company.id}&v=${p.version}" alt="Mağaza logosu">`:''}
    <form id="customerLogo" class="form-grid"><label>Logo dosyası<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" aria-describedby="logoRecommendation" required></label><small class="form-help" id="logoRecommendation">Öneri: şeffaf arka planlı PNG, 512 × 512 piksel. PNG, JPEG veya WebP kabul edilir; en fazla 1 MB ve 2048 × 2048 piksel. Görsel oranı korunur ve logo İş Ortağım’a da yansır.</small>${message}<div class="customer-logo-actions"><button type="submit" class="btn">Logoyu yükle</button>${p.hasLogo?i18nHtml('<button type="button" class="text-btn" id="removeLogo">Logoyu kaldır</button>'):''}</div></form>
   </section>
   <section data-section="shipping" hidden><form class="form-grid" id="customerShipping">${shippingScopeFields(p.shippingScope,{detailed:true})}<p class="form-error full-width" role="alert"></p><button class="btn" type="submit">Sevkiyat kapsamını kaydet</button></form></section>
   <section data-section="mail" hidden><p>${data.mailReady?i18nT('E-posta kuyruğu etkin. Gönderilen durumu, posta sunucusunun mesajı kabul ettiğini belirtir; gelen kutusuna teslim garantisi değildir.'):i18nT('E-posta gönderimi henüz etkin değil. Gönderen hesabı ve zamanlanmış kuyruk işçisi kurulmalı.')}</p>
    <ul class="audit-list">${data.mail.map(m=>i18nHtml`<li><strong>${esc(m.email)}</strong><span>${esc(({pending:i18nT('Sırada'),running:i18nT('Gönderiliyor'),sent:'Posta sunucusuna iletildi',failed:i18nT('Gönderilemedi'),cancelled:i18nT('İptal edildi')})[m.state]||m.state)} · ${Number(m.attempts)} deneme · ${esc(new Date(m.created).toLocaleString(currentLocale()))}</span></li>`).join('')||i18nHtml('<li>Henüz e-posta işlemi yok.</li>')}</ul>
   </section><section role="tabpanel" aria-labelledby="customerMenuLabelsTab" id="customerMenuLabels" data-section="menu-labels" data-menu-label-editor hidden></section>`,{className:'customer-settings-dialog customer-management-dialog'});
  protectManagementDialog(dialog);
  bindManagementCompany(dialog,state.companies,companyId,{current,onSelect:id=>customerManagement(state,onSave,selectedTab,id)});
  const menuLabels=dialog.querySelector('[data-menu-label-editor]');
  const loadMenuLabels=bindCustomerMenuLabelEditor(menuLabels,{endpoint:endpoint('menu-labels'),company:companyId,state,
   current:()=>current()&&dialog.open&&dialog.contains(menuLabels)});
  const shippingForm=dialog.querySelector('#customerShipping'),shipping=bindShippingScope(shippingForm);
  bindForm(shippingForm,async()=>{if(!current())return;await api(endpoint('customer'),'PUT',{...p,name:company.name,shippingScope:shipping.value(),version:p.version});Object.assign(p,(await api(endpoint('customer'))).profile);await onSave();});
  dialog.querySelectorAll('[data-tab]').forEach(button=>button.onclick=async()=>{
   if(button.getAttribute('aria-selected')!=='true'&&!await canLeaveDialogDrafts({withinDialog:true}))return;
   if(!dialog.contains(button)||!sameWorkspace(state,token))return;
   selectedTab=button.dataset.tab;
   dialog.querySelectorAll('[data-tab]').forEach(b=>b.setAttribute('aria-selected',String(b===button)));
   dialog.querySelectorAll('[data-section]').forEach(s=>s.hidden=s.dataset.section!==button.dataset.tab);
   if(selectedTab==='menu-labels')void loadMenuLabels();
  });
  dialog.querySelector(`[data-tab="${['profile','logo','mail','shipping','menu-labels'].includes(selectedTab)?selectedTab:'profile'}"]`).click();
  const refresh=async text=>{if(!current()||!dialog.open)return;const owner=dialog.querySelector('.dialog-body');await onSave();if(!current()||!dialog.open||dialog.querySelector('.dialog-body')!==owner)return;toast(text);await reopen();};
  bindForm(dialog.querySelector('#customerProfile'),async values=>{await api(endpoint('customer'),'PUT',{...values,version:p.version});await refresh(i18nT('Mağaza bilgileri kaydedildi.'));});
  bindForm(dialog.querySelector('#customerLogo'),async()=>{const form=dialog.querySelector('#customerLogo');const file=form.elements.logo.files[0];if(file.size>1048576)throw new Error(i18nT('Logo en fazla 1 MB olmalı.'));await upload(endpoint('logo'),new FormData(form));await refresh(i18nT('Mağaza logosu güncellendi.'));});
  dialog.querySelector('#removeLogo')?.addEventListener('click',async()=>{try{await api(endpoint('logo'),'DELETE');await refresh(i18nT('Logo kaldırıldı.'));}catch(e){toast(e.message);}});
 }catch(error){toast(error.message);}finally{finishAccessCover();}
}

export function forgotPassword(email=''){
 const dialog=openDialog(i18nT('Şifremi unuttum'),i18nHtml`<p>Kayıtlı e-posta adresinize 30 dakika geçerli, tek kullanımlık bir yenileme bağlantısı gönderilir.</p><form class="form-grid">${input(i18nT('E-posta'),'email',typeof email==='string'?email:'','email')}${message}<button type="submit" class="btn primary">Yenileme bağlantısı iste</button></form>`);
 bindForm(dialog.querySelector('form'),async data=>{const result=await api('/password/forgot','POST',data);dialog.close();toast(result.message);});
}
export function resetPasswordFromLink(){
 const match=location.hash.match(/^#reset=([a-f0-9]{64})$/);if(!match)return;
 const token=match[1];history.replaceState(null,'',location.pathname+location.search);
 const dialog=openDialog(i18nT('Yeni şifrenizi belirleyin'),i18nHtml`<form class="form-grid"><label>Yeni şifre<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label><label>Yeni şifre tekrar<input name="confirm" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label><p class="form-help">En az 12 karakter kullanın. İşlemden sonra açık oturumlarınız kapatılır.</p>${message}<button class="btn primary" type="submit">Şifreyi yenile</button></form>`);
 bindForm(dialog.querySelector('form'),async data=>{if(data.password!==data.confirm)throw new Error(i18nT('Şifreler aynı değil.'));const result=await api('/password/reset','POST',{token,password:data.password});dialog.close();toast(result.message);});
}

