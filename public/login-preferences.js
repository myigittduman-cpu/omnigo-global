import {html as i18nHtml} from './i18n.js';
import {readRememberedLogin,writeRememberedLogin,clearRememberedLogin} from './login-preference-store.js';
export const clearRememberedEmail = clearRememberedLogin;
export function attachLoginPreferences(form,{compact=false,preserveValues=false}={}) {
  const remembered = readRememberedLogin();
  const existing=form.querySelector('.login-preferences');
  const options = existing||document.createElement('div');
  options.className = 'login-preferences';
  if(!existing)options.innerHTML = compact?i18nHtml('<label class="login-choice"><input type="checkbox" name="rememberEmail"><span>Beni Hatırla</span></label>'):i18nHtml('<label class="login-choice"><input type="checkbox" name="rememberEmail"><span>Beni Hatırla<small>Bu cihazda kullanıcı adımı veya e-posta adresimi hatırla.</small></span></label><label class="login-choice"><input type="checkbox" name="keepSession"><span>Oturumumu Açık Tut<small>Çıkış yapana veya bu seçeneği kapatana kadar oturumunuz açık kalır. Yalnızca kişisel cihazınızda kullanın.</small></span></label>');
  if(!existing)form.querySelector('.form-error').before(options);
  const touched=new Set((form.dataset.loginTouched||'').split(','));
  if(!preserveValues||!touched.has('email')&&!form.elements.email.value)form.elements.email.value = remembered?.value || '';
  if(!preserveValues||!touched.has('rememberEmail'))form.elements.rememberEmail.checked = Boolean(remembered);
  form.elements.rememberEmail.addEventListener('change', () => {
    if (!form.elements.rememberEmail.checked) {
      clearRememberedEmail();
    }
  });
}
export function saveLoginPreferences(form) {
  if (form.elements.rememberEmail.checked) writeRememberedLogin({mode:form.elements.loginType?.value || 'email',value:form.elements.email.value});
  else clearRememberedLogin();
}
