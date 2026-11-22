import {api} from './api.js';
import {t,html} from './i18n.js';
import {attachCredentialVisibility} from './credential-visibility.js';

/** Reveals only on demand; never persists credentials in browser storage. */
export function bindCredentialEditor(form,{provider,company,status,current,lockedFields=[],onVersion}){
 const inputs=[...form.querySelectorAll('input[name]')],submit=form.querySelector('[type=submit]');
 const originalTypes=new Map(inputs.map(input=>[input,input.type]));
 const bar=document.createElement('div');bar.className='support-toolbar full-width';
 bar.innerHTML=html`<button type="button" class="btn" data-credential-show>Kayıtlı bilgileri göster</button><button type="button" class="btn" data-credential-edit>Düzenle</button><button type="button" class="btn" data-credential-cancel hidden>Vazgeç</button><span data-credential-status role="status"></span>`;
 form.prepend(bar);
 const show=bar.querySelector('[data-credential-show]'),edit=bar.querySelector('[data-credential-edit]'),cancel=bar.querySelector('[data-credential-cancel]'),message=bar.querySelector('[data-credential-status]');
 let saved=null,editing=!status.configured,revealed=false,busy=false;
 const visibleFields=new Set(),toggles=new Map();
 const valid=()=>form.isConnected&&current();
 const paint=()=>{
  bar.hidden=!status.configured;
  for(const input of inputs){const visible=revealed||visibleFields.has(input);input.readOnly=!editing||lockedFields.includes(input.name);input.type=visible&&originalTypes.get(input)==='password'?'text':originalTypes.get(input);toggles.get(input)?.update(visible,busy);}
  submit.disabled=busy||!editing;edit.disabled=busy||editing;show.disabled=busy;cancel.hidden=!editing;cancel.disabled=busy;
  show.textContent=t(revealed?'Bilgileri gizle':'Kayıtlı bilgileri göster');
 };
 const clear=()=>{saved=null;revealed=false;visibleFields.clear();editing=!status.configured;for(const input of inputs)input.value='';paint();};
 const load=async()=>{
  if(saved||!status.configured)return true;
  const result=await api(`/integrations/${provider}/credentials?company=${company}`,'POST',{});
  if(!valid())return false;
  if(result.company!==Number(company)||result.provider!==provider)throw new Error(t('Bağlantı bilgileri doğrulanamadı.'));
  saved=result.fields;status.version=result.version;onVersion?.(result.version);
  for(const input of inputs)input.value=String(saved[input.name]??'');
  return true;
 };
 const run=async action=>{
  if(busy||!valid())return;busy=true;message.textContent='';paint();
  try{if(await load())action();}catch(error){if(valid())message.textContent=error.message;}
  finally{busy=false;if(valid())paint();else clear();}
 };
 for(const input of inputs.filter(input=>originalTypes.get(input)==='password'))toggles.set(input,attachCredentialVisibility(input,()=>run(()=>{
  const visible=revealed||visibleFields.has(input);
  if(revealed){for(const field of toggles.keys())visibleFields.add(field);revealed=false;}
  if(visible)visibleFields.delete(input);else visibleFields.add(input);
 })));
 show.onclick=()=>run(()=>{revealed=!revealed;visibleFields.clear();});
 edit.onclick=()=>run(()=>{editing=true;inputs.find(input=>!lockedFields.includes(input.name))?.focus();});
 cancel.onclick=()=>{for(const input of inputs)input.value=String(saved?.[input.name]??'');editing=false;revealed=false;visibleFields.clear();paint();};
 // Existing save handlers and server-side version checks remain responsible for writes.
 form.addEventListener('submit',event=>{if(!valid()||!editing||busy){event.preventDefault();event.stopImmediatePropagation();}},true);
 form.closest('dialog')?.addEventListener('close',clear,{once:true});
 paint();
 return {reset(){status.configured=true;clear();}};
}
