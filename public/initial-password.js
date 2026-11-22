import {api} from './api.js';
import {html,t} from './i18n.js';
import {attachPasswordFields} from './login-visibility.js';

export function initialPasswordScreen(root,onDone,onLogout){
 root.innerHTML=html`<main class="initial-password-screen"><section class="panel"><h1>İlk giriş şifrenizi değiştirin</h1><p>Size verilen şifre geçicidir. Hesabınıza devam etmek için yalnız sizin bildiğiniz yeni bir şifre belirleyin.</p><form class="form-grid"><label>Geçici şifre<input name="currentPassword" type="password" required maxlength="128" autocomplete="current-password"></label><label>Yeni şifre<input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password"><small>En az 12 karakter kullanın.</small></label><label>Yeni şifre tekrar<input name="confirm" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label><p class="form-error" role="alert"></p><button class="btn primary" type="submit">Yeni şifreyi kaydet</button><button class="btn" type="button" data-sign-out>Çıkış yap</button></form></section></main>`;
 const form=root.querySelector('form');attachPasswordFields(form,['currentPassword','password','confirm']);form.querySelector('[data-sign-out]').onclick=onLogout;
 form.onsubmit=async event=>{event.preventDefault();const error=form.querySelector('[role=alert]'),submit=form.querySelector('[type=submit]');error.textContent='';if(form.elements.password.value!==form.elements.confirm.value){error.textContent=t('Şifreler aynı değil.');return;}submit.disabled=true;try{await api('/account/initial-password','POST',{password:form.elements.password.value,currentPassword:form.elements.currentPassword.value});form.reset();onDone();}catch(e){error.textContent=e.message;}finally{submit.disabled=false;}};
}
