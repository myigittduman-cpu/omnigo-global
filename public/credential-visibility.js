import {t,html} from './i18n.js';

const eye=html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>`;
const eyeOff=html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a11 11 0 0 0 5.8-1.7M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>`;
let sequence=0;

/** Visibility is controlled by the credential editor, including its async tenant guard. */
export function attachCredentialVisibility(input,onToggle){
 const wrapper=document.createElement('span');wrapper.className='login-password-control credential-password-control';
 const button=document.createElement('button');button.type='button';button.className='login-password-toggle';
 input.id||=`credential-password-${++sequence}`;button.setAttribute('aria-controls',input.id);
 input.before(wrapper);wrapper.append(input,button);button.onclick=onToggle;
 return {update(visible,busy){
  const label=t(visible?'Şifreyi gizle':'Şifreyi göster');
  button.innerHTML=visible?eyeOff:eye;button.setAttribute('aria-label',label);button.setAttribute('aria-pressed',String(visible));button.title=label;button.disabled=busy||input.disabled;
 }};
}
