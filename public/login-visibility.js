import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const eye=i18nHtml('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>');
const eyeOff=i18nHtml('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.2 6.2A19 19 0 0 0 2 12s3.5 7 10 7a11 11 0 0 0 5.8-1.7M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>');
export function passwordControlMarkup(){
 return i18nHtml`<span class="login-password-control"><input id="login-password" name="password" type="password" autocomplete="current-password" aria-label="Şifre" required maxlength="128"><button type="button" class="login-password-toggle" data-password-visibility aria-controls="login-password" aria-label="Şifreyi göster" aria-pressed="false" title="Şifreyi göster">${eye}</button></span>`;
}

function updateVisibility(input,visible){
 input.type=visible?'text':'password';
 const button=input.parentElement.querySelector('[data-password-visibility]');
 if(!button)return;
 const label=visible?i18nT('Şifreyi gizle'):i18nT('Şifreyi göster');
 button.innerHTML=visible?eyeOff:eye;
 button.setAttribute('aria-label',label);button.setAttribute('aria-pressed',String(visible));button.title=label;
 button.disabled=input.disabled;
}
export function hidePassword(input){updateVisibility(input,false);}

export function attachPasswordVisibility(form){
 const input=form.elements.password;if(!input||form.dataset.passwordVisibilityBound==='true')return;form.dataset.passwordVisibilityBound='true';
 let wrapper=input.parentElement;if(!wrapper.classList.contains('login-password-control')){wrapper=document.createElement('span');wrapper.className='login-password-control';input.before(wrapper);wrapper.append(input);}
 let button=wrapper.querySelector('[data-password-visibility]');if(!button){button=document.createElement('button');button.type='button';button.className='login-password-toggle';button.dataset.passwordVisibility='';wrapper.append(button);}
 input.id||='login-password';input.setAttribute('aria-label',i18nT('Şifre'));button.setAttribute('aria-controls',input.id);updateVisibility(input,input.type==='text');
 button.addEventListener('click',()=>{if(!input.disabled)updateVisibility(input,input.type==='password');});
 form.addEventListener('submit',()=>hidePassword(input));
 form.addEventListener('reset',()=>hidePassword(input));
}

/** Share the approved eye control with multi-field password setup forms. */
export function attachPasswordFields(form,names){
 for(const name of names){
  const input=form.elements.namedItem(name);if(!input||input.dataset.passwordVisibilityBound==='true')continue;
  input.dataset.passwordVisibilityBound='true';
  const caption=[...(input.closest('label')?.childNodes||[])].filter(node=>node.nodeType===3).map(node=>node.textContent.trim()).join(' ')||i18nT('Şifre');
  const wrapper=document.createElement('span');wrapper.className='login-password-control credential-password-control';input.before(wrapper);wrapper.append(input);
  input.id||='initial-'+name;input.setAttribute('aria-label',caption);
  const button=document.createElement('button');button.type='button';button.className='login-password-toggle';button.dataset.passwordVisibility='';button.setAttribute('aria-controls',input.id);wrapper.append(button);updateVisibility(input,false);
  button.addEventListener('click',()=>{if(!input.disabled)updateVisibility(input,input.type==='password');});
  form.addEventListener('submit',()=>hidePassword(input));form.addEventListener('reset',()=>hidePassword(input));
 }
}
