import {t} from './i18n.js';
import {readRememberedLogin} from './login-preference-store.js';
/** One text field accepts either identifier; old native profile choices remain compatible. */
export function attachLoginIdentity(form,{preserveValues=false}={}){
 const input=form.elements.email,label=input.closest('label');
 const title=label.querySelector('[data-login-identity-label]')||document.createElement('span');title.textContent=t('E-posta veya kullanıcı adı');
 for(const node of [...label.childNodes])if(node.nodeType===3)node.remove();if(!title.parentElement)label.prepend(title);
 let choice=form.elements.loginType;if(!choice){choice=document.createElement('input');choice.type='hidden';choice.name='loginType';form.append(choice);}choice.value='auto';
 const set=(mode='auto',value='')=>{choice.value=['email','username'].includes(mode)?mode:'auto';input.type='text';input.value=value;input.readOnly=false;input.maxLength=1024;input.autocomplete='username';input.placeholder=t('E-posta veya kullanıcı adı');input.spellcheck=false;input.setAttribute('autocapitalize','none');};
 input.addEventListener('input',()=>{choice.value='auto';form.dispatchEvent(new CustomEvent('omnigo-login-identity'));});
 input.addEventListener('change',()=>{if(!input.readOnly)choice.value='auto';});
 const remembered=readRememberedLogin(),touched=(form.dataset.loginTouched||'').split(',').includes('email');set('auto',preserveValues&&(touched||input.value)?input.value:remembered?.value||input.value);
 form.loginIdentity={set,choice};return form.loginIdentity;
}
