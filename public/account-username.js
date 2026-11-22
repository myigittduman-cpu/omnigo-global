import {api} from './api.js';
import {t,html} from './i18n.js';
import {esc} from './ui.js';
export function usernameSettings(user){
 return user.role==='admin'?html`<form data-account-username><h3>Kullanıcı adı</h3><label class="settings-field">Kullanıcı adı<input name="username" value="${esc(user.username||'')}" minlength="5" required autocomplete="username"></label><small>En az 5 karakter. E-posta adresiniz ve şifreniz değişmez.</small><button class="btn primary" type="submit">Kaydet</button></form>`:html`<div class="settings-identity"><span>Kullanıcı adı: ${esc(user.username||t('Henüz atanmadı'))}</span><small>Kullanıcı adını yalnız yönetici değiştirebilir.</small></div>`;
}
export function bindUsernameSettings(body,user,{drafts,active}){
 const form=body.querySelector('[data-account-username]');if(!form)return;
 drafts.track(form,{label:t('Kullanıcı adı'),save:async values=>{
  const result=await api('/account/username','PUT',{username:values.username,previousUsername:user.username??null});
  if(!active())return false;user.username=result.username;body.querySelector('[data-settings-status]').textContent=t('Kullanıcı adı kaydedildi.');return true;
 }});
}
