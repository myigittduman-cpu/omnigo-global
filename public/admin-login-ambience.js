import {html} from './i18n.js';

/** Decorative brand copy stays outside the sign-in flow and accessibility tree. */
export function adminLoginAmbience(){
 return html`<div class="admin-login-ambience" aria-hidden="true">
  <div class="admin-login-scene admin-login-scene--left"><div class="admin-login-copy"><span>OMNIGO GLOBAL</span><p>Her kanal.<br><strong>Tek bir merkez.</strong></p><i></i></div></div>
  <div class="admin-login-scene admin-login-scene--right"><div class="admin-login-copy"><span>E-TİCARET OPERASYONLARI</span><p>Net veriler.<br><strong>Güçlü kararlar.</strong></p><i></i></div></div>
 </div>`;
}
