import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export function loginVisual() {
 return i18nHtml`<div class="login-ambient" aria-hidden="true"><span></span><span></span><span></span></div><div class="login-visual" aria-label="Omnigo Global">
  <span class="login-visual-halo" aria-hidden="true"></span>
  <span class="login-visual-orbit" aria-hidden="true"></span>
  <span class="login-visual-orbit outer" aria-hidden="true"></span>
  <img src="assets/omnigo-logo.png" alt="Omnigo Global" width="300" height="169">
 </div>`;
}
