/* Preserve deliberate input and clicks while the full workspace controller loads. No credentials are stored here. */
(()=>{
 const main=document.querySelector('[data-login-initial]'),form=main?.querySelector('#loginForm');if(!form)return;
 const touched=new Set();let pendingSubmit=false,pendingClick=null;
 const onInput=event=>{if(event.target.name){touched.add(event.target.name);form.dataset.loginTouched=[...touched].join(',');}};
 const onSubmit=event=>{if(form.dataset.loginBound==='true')return;event.preventDefault();pendingSubmit=true;};
 const onClick=event=>{
  if(form.dataset.loginBound==='true')return;
  const eye=event.target.closest('[data-password-visibility]');
  if(eye){event.preventDefault();const visible=form.elements.password.type==='password';form.elements.password.type=visible?'text':'password';eye.setAttribute('aria-pressed',String(visible));eye.setAttribute('aria-label',visible?'Şifreyi gizle':'Şifreyi göster');return;}
  const action=event.target.closest('.login-foot a,#forgotPassword');if(action){event.preventDefault();event.stopImmediatePropagation();pendingClick=action;}
 };
 form.addEventListener('input',onInput);form.addEventListener('change',onInput);form.addEventListener('submit',onSubmit);main.addEventListener('click',onClick,true);
 window.addEventListener('omnigo-login-bound',()=>{
  form.removeEventListener('submit',onSubmit);form.removeEventListener('input',onInput);form.removeEventListener('change',onInput);main.removeEventListener('click',onClick,true);
  if(!form.isConnected)return;if(pendingSubmit)form.requestSubmit();else if(pendingClick?.isConnected)pendingClick.click();
 },{once:true});
})();
