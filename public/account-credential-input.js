import {t} from './i18n.js';

// Only the draft typed in this form can be revealed.
export function bindAccountPassword(input){
  const toggle=document.createElement('button');
  toggle.type='button';
  toggle.className='text-btn';
  toggle.textContent=t('Şifreyi göster');
  toggle.setAttribute('aria-pressed','false');
  input.closest('label').append(toggle);
  toggle.addEventListener('click',()=>{
    const reveal=input.type==='password';
    input.type=reveal?'text':'password';
    toggle.setAttribute('aria-pressed',String(reveal));
    toggle.textContent=t(reveal?'Şifreyi gizle':'Şifreyi göster');
  });
  const validate=()=>{
    const value=input.value;
    const invalid=value.length>0&&(!value.trim()||value.includes('\0')||new TextEncoder().encode(value).length>128);
    input.setCustomValidity(invalid?t('Şifre boş olamaz ve UTF-8 biçiminde 128 baytı aşamaz.'):'');
  };
  input.addEventListener('input',validate);
  input.addEventListener('change',validate);
  return validate;
}
