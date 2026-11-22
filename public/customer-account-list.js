import {html,t} from './i18n.js';
import {accountDirectoryRows} from './account-directory-view.js';

export function customerAccountList(users,mailReady,company){
 const active=users.filter(u=>Number(u.active)).length;
 const rows=accountDirectoryRows({items:users,companies:[company]},{editAttribute:'data-edit-account',extraActions:user=>html`<details class="account-row-actions">
  <summary>Diğer işlemler</summary>
  <button class="text-btn" type="button" data-revoke="${Number(user.id)}">Açık oturumları kapat</button>
  <button class="text-btn" type="button" data-reset="${Number(user.id)}" ${!mailReady||!Number(user.active)?'disabled':''}>Şifre yenileme e-postası gönder</button>
 </details>`});
 return html`<div class="account-directory-heading"><div><h3>Kullanıcı yönetimi</h3>
  <p class="form-help">${users.length} ${t('kullanıcı')} · ${active} ${t('aktif')} · ${users.length-active} ${t('pasif')} · Kullanıcı sınırı yok</p>
 </div><button class="btn" type="button" data-add-customer-account>Yeni kullanıcı ekle</button></div>${rows}`;
}
