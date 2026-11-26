import {html,t} from './i18n.js';
import {bindAccountPassword} from './account-credential-input.js';
import {esc} from './ui.js';
import {hasUserPortrait,userPortraitMarkup} from './user-portrait.js';
import {customerAccountRoleField,customerAccountRoleLabel} from './customer-account-roles.js';
export {portraitUrl} from './user-portrait.js';
export function accountDirectoryShell(){
 return html`<div data-account-directory>
  <div class="account-directory-heading"><div><h3>Kullanıcı hesapları</h3><p class="form-help">Hesap bilgilerini, bağlı mağazayı ve hesap durumunu düzenleyin.</p></div>
   <button class="btn" type="button" data-directory-create>Yeni kullanıcı ekle</button>
  </div>
  <div class="account-directory-tabs" role="tablist" aria-label="Kullanıcı türleri">
   <button id="directory-tab-client" class="btn" type="button" role="tab" data-directory-tab="client" aria-controls="directory-panel" aria-selected="true">Mağaza Kullanıcıları</button>
   <button id="directory-tab-admin" class="btn" type="button" role="tab" data-directory-tab="admin" aria-controls="directory-panel" aria-selected="false" tabindex="-1">Omnigo Kullanıcıları</button>
  </div>
  <section id="directory-panel" role="tabpanel" aria-labelledby="directory-tab-client">
   <form class="account-directory-filters" data-directory-filters>
    <label>Kullanıcı ara<input name="q" type="search" placeholder="Ad, kullanıcı adı veya e-posta" autocomplete="off"></label>
    <label data-directory-store-filter>Mağaza<select name="company"><option value="">Tüm mağazalar</option></select></label>
   </form>
   <p data-directory-status role="status"></p>
   <div data-directory-list></div><div class="account-directory-pages" data-directory-pages></div>
  </section>
 </div>`;
}
export function accountDirectoryRows(data,{editAttribute='data-directory-edit',extraActions=null}={}){
 const companies=new Map(data.companies.map(c=>[Number(c.id),c.name]));
 return html`<div class="account-directory-scroll" tabindex="0" aria-label="Kullanıcı tablosu">
  <table class="account-directory-table"><thead><tr><th>Kullanıcı</th><th>Kullanıcı adı / e-posta</th><th>Rol</th><th>Bağlı mağaza</th><th>Durum</th><th>İşlemler</th></tr></thead>
  <tbody>${data.items.map(user=>html`<tr>
   <td><div class="account-directory-identity"><span class="avatar${user.role!=='admin'&&hasUserPortrait(user)?' account-directory-store-logo':''}">${userPortraitMarkup(user,{fit:true})}</span><strong>${esc(user.name)}</strong></div></td>
   <td>${esc(user.username||'—')}<small>${esc(user.email)}</small></td>
   <td>${esc(customerAccountRoleLabel(user))}</td>
   <td>${esc(user.role==='admin'?t('Tüm mağazalar'):companies.get(Number(user.companyId))||'—')}</td>
   <td>${t(Number(user.active)?'Aktif':'Pasif')}${Object.hasOwn(user,'sessions')?html`<small>Açık oturum: ${Number(user.sessions)}</small>`:''}</td>
   <td><div class="account-directory-actions"><button class="btn" type="button" ${editAttribute}="${Number(user.id)}">Düzenle</button>
    ${user.role==='admin'?html`<small>Tam yönetici erişimi</small>`:''}
   </div>${extraActions?.(user)||''}</td>
  </tr>`).join('')||html`<tr><td colspan="6">Aramanıza uygun kullanıcı bulunamadı.</td></tr>`}</tbody>
  </table></div>`;
}
export function accountDirectoryPortrait(user){
 return html`<section class="account-directory-portrait"><h3>Profil resmi</h3>${hasUserPortrait(user)?userPortraitMarkup(user,{alt:t('Mevcut profil resmi')}):''}${user.role==='admin'?html`<p class="form-help">Tüm yönetici hesaplarında ortak Omnigo profil logosu otomatik kullanılır.</p>`:html`<p class="form-help">Profil resmi bağlı mağazanın logosundan otomatik alınır. Mağaza logosu değiştiğinde tüm müşteri kullanıcılarına yansır.</p>`}</section>`;
}
export function accountDirectoryForm(user,companies,{accountType='client'}={}){
 const role=user?.role||accountType;
 return html`<form class="form-grid account-directory-form" data-directory-form data-saved-role="${esc(user?.role||'')}" autocomplete="off">
  <label>Ad soyad<input name="name" required maxlength="100" value="${esc(user?.name||'')}"></label>
  <label>E-posta<input name="email" type="email" required maxlength="200" value="${esc(user?.email||'')}"></label>
  <label>Kullanıcı adı<input name="username" required minlength="5" maxlength="100" value="${esc(user?.username||'')}"></label>
  <label>Hesap türü<select name="role"><option value="client" ${role==='client'?'selected':''}>Müşteri</option><option value="admin" ${role==='admin'?'selected':''}>Yönetici</option></select><small>Yönetici tüm mağazalara ve yönetim işlemlerine erişir.</small></label>
  <label data-directory-company>Bağlı mağaza<select name="companyId"><option value="">Mağaza seçin</option>${companies.map(c=>html`<option value="${Number(c.id)}" ${Number(user?.companyId)===Number(c.id)?'selected':''}>${esc(c.name)}</option>`).join('')}</select></label>
  ${user?'':customerAccountRoleField()}
  <label>Hesap durumu<select name="active"><option value="true" ${user?.active!==false?'selected':''}>Aktif</option><option value="false" ${user?.active===false?'selected':''}>Pasif</option></select></label>
  <label class="full-width">${user?t('Yeni şifre (değiştirmek için)'):t('Kullanıcı şifresi')}<input name="password" type="password" ${user?'':'required'} autocomplete="new-password"><small data-password-help></small></label>
  <p class="form-error full-width" role="alert"></p>
  <div class="settings-actions full-width"><button class="btn" type="submit">Kaydet</button></div>
 </form>`;
}

export function bindAccountDirectoryScope(form){
 const company=form.elements.companyId,storeRole=form.elements.storeRole;
 const password=form.elements.password;
 bindAccountPassword(password);
 const help=form.querySelector('[data-password-help]');
 const update=()=>{
  const customer=form.elements.role.value==='client';
  const promoting=form.dataset.savedRole==='client'&&!customer;
  password.required=!form.dataset.savedRole||promoting;
  if(customer)password.removeAttribute('minlength');
  else password.minLength=12;
  help.textContent=t(customer?'Belirlediğiniz şifre ilk girişten sonra da geçerlidir.':
    promoting?'Omnigo yöneticisi için en az 12 karakterli yeni bir şifre belirleyin.':'Yönetici şifresi en az 12 karakter olmalı.');
  company.closest('label').hidden=!customer;
  company.required=customer;
  if(storeRole){
   storeRole.closest('label').hidden=!customer;
   storeRole.disabled=!customer;
  }
 };
 update();
 form.elements.role.addEventListener('change',update);
}
