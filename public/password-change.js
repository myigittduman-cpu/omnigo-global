import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc} from './ui.js';
import {attachPasswordVisibility} from './login-visibility.js';
export function passwordChangeView(){
 return i18nHtml`<form data-password-change class="settings-password"><p class="settings-note">Mevcut şifrenizi doğrulayın, yeni şifrenizi iki kez yazın. Kayıtlı e-posta adresinize gönderilen kodu onayladığınızda değişiklik tamamlanır.</p><label>Mevcut şifre<input name="currentPassword" type="password" autocomplete="current-password" required maxlength="128"><small>Hesabı kullanan kişinin siz olduğunuzu doğrular.</small></label><label>Yeni şifre<input name="newPassword" type="password" autocomplete="new-password" minlength="12" maxlength="128" required><small>En az 12 karakter; mevcut şifrenizden farklı olmalı.</small></label><label>Yeni şifre tekrar<input name="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="128" required><small>Yazım hatalarını önlemek için yeni şifrenizi tekrar girin.</small></label><p class="settings-note">Bu işlem hesap şifresini değiştirir. Onaydan sonra bu hesaba ait tüm oturumlar kapanır ve yeni şifreyle giriş yapılır.</p><p class="form-error" role="alert" data-password-error></p><button class="btn primary" type="submit">Onay kodu gönder</button></form>`;
}
export function bindPasswordChange(root,onComplete){
 const form=root.querySelector('[data-password-change]');if(!form)return;
 attachPasswordVisibility(form);let busy=false;
 form.onsubmit=async event=>{
  event.preventDefault();if(busy)return;const data=Object.fromEntries(new FormData(form));const error=form.querySelector('[data-password-error]');
  if(data.newPassword!==data.confirmPassword){error.textContent=i18nT('Yeni şifreler aynı olmalı.');return;}
  busy=true;form.querySelector('button[type="submit"]').disabled=true;error.textContent='';
  try{const challenge=await api('/account/password/request','POST',data);form.reset();if(root.isConnected)renderCode(challenge);}
  catch(failure){error.textContent=failure.message;}
  finally{busy=false;if(form.isConnected)form.querySelector('button[type="submit"]').disabled=false;}
 };
 function renderCode(challenge){
  root.innerHTML=i18nHtml`<form data-password-code class="settings-password"><p class="settings-note"><strong>${esc(challenge.email)}</strong> adresine onay kodu gönderilmek üzere sıraya alındı. Kod 10 dakika geçerlidir.</p><label>E-posta onay kodu<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required><small>E-postadaki 6 haneli kodu girin. Kod doğrulanana kadar mevcut şifreniz geçerlidir.</small></label><p class="form-error" role="alert" data-code-error></p><p role="status" data-code-status></p><div class="settings-actions"><button class="btn" type="button" data-resend>Kodu tekrar gönder</button><button class="btn primary" type="submit">Onayla ve şifreyi değiştir</button></div><small>Yeni kod isteği için bir dakika bekleyin. Önceki kod geçersiz olur.</small></form>`;
  const codeForm=root.querySelector('form'),error=codeForm.querySelector('[data-code-error]');codeForm.elements.code.focus();
  const run=async action=>{if(busy)return;busy=true;codeForm.querySelectorAll('button').forEach(b=>b.disabled=true);error.textContent='';try{await action();}catch(failure){error.textContent=failure.message;}finally{busy=false;codeForm.querySelectorAll('button').forEach(b=>b.disabled=false);}};
  codeForm.onsubmit=event=>{event.preventDefault();void run(async()=>{await api('/account/password/verify','POST',{token:challenge.token,code:codeForm.elements.code.value});challenge.token='';await onComplete();});};
  codeForm.querySelector('[data-resend]').onclick=()=>run(async()=>{await api('/account/password/resend','POST',{token:challenge.token});codeForm.querySelector('[data-code-status]').textContent=i18nT('Yeni kod gönderilmek üzere sıraya alındı.');});
 }
}
