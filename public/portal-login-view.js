import {html as i18nHtml,t} from './i18n.js';
import {esc} from './ui.js';
import {loginVisual} from './login-visual.js';
import {passwordControlMarkup} from './login-visibility.js';

const copy=Object.freeze({
 admin:{name:'Omnigo Global Yönetici Portalı',eyebrow:'OPERASYONUN KONTROL MERKEZİ',first:'Süreçleri yönetin.',second:'İşletmeleri büyütün.'},
 client:{name:'Omnigo Global İş Ortağım',eyebrow:'MARKANIZIN ÇALIŞMA ALANI',first:'Sonuçları görün.',second:'İlerlemeyi takip edin.'},
});
export function portalLoginView({role,error='',demo=false,initial=false}={}){
 if(!Object.hasOwn(copy,role))throw new Error('Giriş ekranı rolü geçersiz.');
 const text=copy[role];
 return i18nHtml`<main class="login-layout portal-login-layout" data-login-role="${role}" ${initial?'data-login-initial="true"':''}>
 <section class="login-story portal-login-story">${loginVisual()}<div class="portal-login-slogan"><span class="eyebrow">${t(text.eyebrow)}</span><h1>${t(text.first)}<br><em>${t(text.second)}</em></h1></div><p>Panel satışlarınız, aktif hakedişleriniz ve devam eden çalışmalar tek bir yerde.</p><div class="login-points"><span>01 / Kanal performansı</span><span>02 / Ödeme takvimi</span><span>03 / Aktif çalışmalar</span></div></section>
 <section class="login-panel admin-login-panel" aria-labelledby="portalLoginTitle"><div class="admin-login-surface">
  <header class="admin-login-heading"><img class="portal-login-emblem" src="assets/omnigo-round-v3.png" alt="Omnigo Global" width="1280" height="1280"><span class="portal-login-name" translate="no">${esc(text.name)}</span><h1 id="portalLoginTitle">Hoş geldiniz</h1><p>Omnigo hesabınızla devam edin.</p></header>
  ${demo?i18nHtml`<div class="demo-note"><strong>ÖRNEK İNCELEME</strong><span>Gerçek müşteri verisi içermez.<br>${role==='admin'?'Yönetici: yonetici@ornek.test':'Müşteri: musteri@ornek.test'}</span></div>`:''}
  <form id="loginForm" class="form-grid admin-login-form" method="post"><label><span data-login-identity-label>E-posta veya kullanıcı adı</span><input name="email" type="text" autocomplete="username" placeholder="E-posta veya kullanıcı adı" spellcheck="false" autocapitalize="none" required maxlength="1024"></label><label>Şifre${passwordControlMarkup()}</label><div class="login-preferences"><label class="login-choice"><input type="checkbox" name="rememberEmail"><span>Beni Hatırla</span></label></div><input type="hidden" name="loginType" value="auto"><p class="form-error" role="alert">${esc(error)}</p><button class="btn" type="submit">Giriş yap</button></form>
  <button type="button" class="text-btn admin-login-forgot" id="forgotPassword">Şifremi unuttum</button>
  <small class="login-foot"><span>Omnigo Global · E-Ticaret Danışmanlığı</span><span><a href="/privacy.html">Gizlilik</a><a href="/account-deletion.html">Hesap silme</a><a href="/support.html">Destek</a></span></small>
 </div></section></main>`;
}
