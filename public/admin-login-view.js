import {html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
import {adminLoginAmbience} from './admin-login-ambience.js';

/** The administrator form uses the same authentication fields as the shared portal. */
export function adminLoginView({error='',demo=false}={}){
 return i18nHtml`<main class="login-layout admin-login-layout">${adminLoginAmbience()}<section class="login-panel admin-login-panel" aria-labelledby="adminLoginTitle"><div class="admin-login-surface">
  <header class="admin-login-heading"><div class="admin-login-brand"><img src="assets/omnigo-logo.png" alt="Omnigo Global" width="160" height="90"></div><span class="eyebrow">YÖNETİM PANELİ</span><h1 id="adminLoginTitle">Hoş geldiniz</h1><p>Omnigo hesabınızla devam edin.</p></header>
  ${demo?i18nHtml('<div class="demo-note"><strong>ÖRNEK İNCELEME</strong><span>Gerçek müşteri verisi içermez.<br>Yönetici: yonetici@ornek.test</span></div>'):''}
  <form id="loginForm" class="form-grid admin-login-form"><label>E-posta<input name="email" type="email" autocomplete="username" required maxlength="200"></label><label>Şifre<input name="password" type="password" autocomplete="current-password" required maxlength="128"></label><p class="form-error" role="alert">${esc(error)}</p><button class="btn" type="submit">Giriş yap</button></form>
  <button type="button" class="text-btn admin-login-forgot" id="forgotPassword">Şifremi unuttum</button>
  <small class="login-foot"><span>Omnigo Global · E-Ticaret Danışmanlığı</span><span><a href="/privacy.html">Gizlilik</a><a href="/account-deletion.html">Hesap silme</a><a href="/support.html">Destek</a></span></small>
 </div></section></main>`;
}
