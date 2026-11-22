import {hidePassword} from './login-visibility.js';

// This preference contains an identifier only. Passwords belong to the browser's vault.
const preferenceKey='omnigo.remembered-password.v1';
const optOutKey='omnigo.password-autofill-disabled.v1';
const normalize=value=>String(value||'').trim().toLowerCase();
export function attachRememberedCredentials(form,{storage,credentials=globalThis.navigator?.credentials,PasswordCredential=globalThis.PasswordCredential,native=globalThis.__OMNIGO_LOGIN_CREDENTIALS__}={}){
 try{storage??=globalThis.localStorage;}catch{storage=null;}
 const identity=form.elements.email,password=form.elements.password,remember=form.elements.rememberEmail;
 let revision=0,interacted=false,optedOut=false;
 try{optedOut=storage?.getItem(optOutKey)==='true';}catch{}
 let nativeSupported=Boolean(native?.get&&native?.store&&native?.clear);
 const browserSupported=Boolean(PasswordCredential&&credentials?.get&&credentials?.store);
 form.dataset.credentialStorage=nativeSupported?'device-vault':browserSupported?'browser-vault':'autofill';
 const clear=()=>{try{storage?.removeItem(preferenceKey);}catch{}};
 const write=value=>{try{storage?.setItem(preferenceKey,JSON.stringify({identity:value}));}catch{}};
 const optOut=value=>{optedOut=value;try{value?storage?.setItem(optOutKey,'true'):storage?.removeItem(optOutKey);}catch{}password.autocomplete=value?'off':'current-password';identity.autocomplete=value?'off':'username';};
 // Ignore automatic restoration after an explicit opt-out, but allow deliberate typing or manager selection.
 const clearRestoredPassword=()=>{if(form.isConnected&&optedOut&&!remember.checked&&!interacted){identity.value='';password.value='';hidePassword(password);}};
 for(const field of [identity,password]){
  for(const event of ['pointerdown','keydown'])field.addEventListener(event,()=>{interacted=true;});
  field.addEventListener('animationstart',event=>{if(event.animationName==='omnigo-login-autofill')clearRestoredPassword();});
 }
 globalThis.addEventListener?.('pageshow',clearRestoredPassword);
 if(optedOut&&!remember.checked){password.autocomplete='off';identity.autocomplete='off';clearRestoredPassword();}
 let saved;
 try{saved=JSON.parse(storage?.getItem(preferenceKey)||'null');}catch{}
 for(const field of [identity,password])field.addEventListener('input',()=>revision++);
 remember.addEventListener('change',()=>{revision++;optOut(!remember.checked);if(!remember.checked){clear();if(nativeSupported)void native.clear().catch(()=>{});try{void credentials?.preventSilentAccess?.().catch(()=>{});}catch{}}});
 const restore=async()=>{
  if(!(nativeSupported||browserSupported)||!remember.checked||!saved?.identity||normalize(saved.identity)!==normalize(identity.value))return false;
  const ticket=revision,expected=normalize(identity.value);
  try{
   const credential=nativeSupported?await native.get(expected):await credentials.get({password:true,mediation:'silent'});
   if(!form.isConnected||ticket!==revision||!remember.checked||password.value||normalize(identity.value)!==expected||credential?.type!=='password'||normalize(credential.id)!==expected||typeof credential.password!=='string'||!credential.password)return false;
   password.value=credential.password;hidePassword(password);return true;
  }catch{return false;}
 };
 const ready=restore();
 globalThis.window?.addEventListener?.('omnigo-login-credentials-ready',()=>{
  if(!form.isConnected)return;
  const provider=globalThis.__OMNIGO_LOGIN_CREDENTIALS__;
  if(!provider?.get||!provider?.store||!provider?.clear)return;
  native=provider;nativeSupported=true;form.dataset.credentialStorage='device-vault';
  if(optedOut&&!remember.checked)void native.clear().catch(()=>{});else void restore();
 });
 async function rememberCredentials(user){
  if(!remember.checked){clear();optOut(true);if(nativeSupported)try{await native.clear();}catch{}return false;}
  optOut(false);
  const id=identity.value.trim(),secret=password.value;if(!id||!secret)return false;
  write(id);
  if(!(nativeSupported||browserSupported))return false;
  try{
   if(nativeSupported)await native.store({id,password:secret});
   else await credentials.store(new PasswordCredential({id,password:secret,name:user?.name||id}));
   return true;
  }catch{return false;}
 }
 return {ready,rememberCredentials};
}
