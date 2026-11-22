import {t as i18nT} from './i18n.js';
import {hidePassword} from './login-visibility.js';
import {attachRememberedCredentials} from './login-credentials.js';

export const adminProfiles=Object.freeze([
 Object.freeze({name:'Yiğit Duman',email:'m.yigittduman@gmail.com',initials:'YD'}),
 Object.freeze({name:'Mertkal Tekkaya',email:'mertkaltekkaya@gmail.com',initials:'MT'}),
]);

export function accountTitle(user){
 if(user?.role!=='admin')return i18nT('Müşteri hesabı');
 return adminProfiles.some(profile=>profile.email===user.email||profile.name===user.name)?i18nT('E-Ticaret Uzmanı'):i18nT('Yönetici hesabı');
}

export function attachAdminLogin(form,options={}){
 const email=form.elements.email,password=form.elements.password;
 email.readOnly=false;password.disabled=false;password.required=true;password.closest('label').hidden=false;if(!options.preserveValues)hidePassword(password);
 const remembered=attachRememberedCredentials(form,options);
 return {...remembered,resumeAccount:()=>null,expireSession:()=>{password.value='';hidePassword(password);password.focus();}};
}
