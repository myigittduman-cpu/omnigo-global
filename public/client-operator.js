import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api,setOperatorToken} from './api.js';

export function operatorNameError(value){
 if(typeof value!=='string'||/[\p{Cc}\p{Cf}]/u.test(value))return i18nT('İşlem yapacak kişinin adını ve soyadını yazın.');
 const name=value.trim().replace(/\s+/gu,' ');
 if((name.match(/\p{L}/gu)||[]).length<3)return i18nT('Ad soyad alanına en az 3 harf yazın. Boşluk zorunlu değildir.');
 if(Array.from(name).length>100||! /^[\p{L}\p{M}]+(?:['’.-][\p{L}\p{M}]+)*(?: [\p{L}\p{M}]+(?:['’.-][\p{L}\p{M}]+)*)*$/u.test(name))return i18nT('Ad soyad alanını yalnız harflerle yazın (en fazla 100 karakter).');
 return '';
}
export function attachClientOperator(form){
 const label=document.createElement('label');label.textContent=i18nT('İşlem yapacak kişinin adı ve soyadı');
 const input=document.createElement('input');input.name='operatorName';input.autocomplete='off';input.required=true;input.minLength=3;input.maxLength=100;input.placeholder=i18nT('Adınız Soyadınız');
 input.addEventListener('input',()=>input.setCustomValidity(operatorNameError(input.value)));
 const help=document.createElement('small');help.className='form-help';help.id='operator-name-help';help.textContent=i18nT('En az 3 harf. Boşluk bırakmak zorunlu değildir.');input.setAttribute('aria-describedby',help.id);
 label.append(input,help);
 form.querySelector('.form-error').before(label);
}
export async function startClientOperator(user,name){
 setOperatorToken('');
 if(user.role!=='client')return user;
 const error=operatorNameError(name);if(error)throw new Error(error);
 const result=await api('/session/operator','POST',{name});
 setOperatorToken(result.operatorToken);
 return {...user,operatorName:result.operatorName};
}
