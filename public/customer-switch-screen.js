import {addTransitionAmbient} from './transition-ambient.js';
import {html,t,text} from './i18n.js';
import {esc} from './ui.js';

let styles;
function ensureStyles(){
 if(styles)return styles;
 styles=new Promise((resolve,reject)=>{
  const link=document.createElement('link');link.rel='stylesheet';link.href='/customer-switch.css';
  link.onload=resolve;link.onerror=()=>{styles=null;link.remove();reject(new Error(t('Müşteri geçişi hazırlanamadı. Yeniden deneyin.')));};
  document.head.append(link);
 });return styles;
}
if(typeof document!=='undefined')void ensureStyles().catch(()=>{});

export async function confirmCustomerSwitch(name){
 await ensureStyles();const focus=document.activeElement;
 return new Promise(resolve=>{
  const dialog=document.createElement('dialog');dialog.className='action-confirm-dialog customer-switch-confirm';dialog.setAttribute('aria-label',t('Müşteri hesabını değiştir'));
  dialog.innerHTML=html`<h2>Müşteri hesabını değiştir</h2><p>${text`${esc(name)} müşteri hesabına geçmek istiyor musunuz?`}</p><div class="dialog-actions"><button class="btn" type="button" data-switch-cancel>Vazgeç</button><button class="btn primary" type="button" data-switch-approve>Evet, geçiş yap</button></div>`;
  dialog.querySelector('[data-switch-cancel]').onclick=()=>dialog.close('cancel');dialog.querySelector('[data-switch-approve]').onclick=()=>dialog.close('approve');
  dialog.addEventListener('close',()=>{const accepted=dialog.returnValue==='approve';dialog.remove();if(focus?.isConnected)focus.focus({preventScroll:true});resolve(accepted);},{once:true});
  document.body.append(dialog);dialog.showModal();dialog.querySelector('[data-switch-cancel]').focus({preventScroll:true});
 });
}

/** Cover the old/new tenant boundary until both four seconds and the real load complete. */
export async function coverCustomerSwitch(company,work,{duration=4000}={}){
 await ensureStyles();const dialog=document.createElement('dialog');dialog.className='customer-switch-screen';dialog.setAttribute('aria-label',t('Müşteri hesabına geçiliyor'));dialog.setAttribute('aria-busy','true');
 dialog.innerHTML=html`<section class="customer-switch-content"><div class="customer-switch-logo"><img src="/assets/omnigo-logo.png" width="1672" height="941" alt="Omnigo Global · E-Ticaret Danışmanlığı"></div><h2>${text`${esc(company.name)} Müşteri Hesabına Geçiliyor`}</h2><p role="status">Çalışma alanınız hazırlanıyor.</p><div class="customer-switch-progress transition-track" aria-hidden="true"><span></span></div></section>`;
 addTransitionAmbient(dialog);dialog.style.setProperty('--customer-switch-duration',duration+'ms');dialog.addEventListener('cancel',event=>event.preventDefault());
 const image=dialog.querySelector('img');if(typeof image.decode==='function')await image.decode().catch(()=>{});
 document.body.append(dialog);dialog.showModal();const started=performance.now();
 const frame=()=>new Promise(resolve=>requestAnimationFrame(resolve));
 try{
  await frame();await frame();dialog.classList.add('is-progressing');
  const outcome=await work(),remaining=Math.max(0,duration-(performance.now()-started));
  if(remaining)await new Promise(resolve=>setTimeout(resolve,remaining));
  dialog.classList.add('is-complete');dialog.setAttribute('aria-busy','false');await frame();
  return outcome;
 }finally{
  dialog.close();dialog.remove();
  const heading=document.querySelector('#app .main h1');if(heading){if(!heading.hasAttribute('tabindex'))heading.setAttribute('tabindex','-1');heading.focus({preventScroll:true});}
 }
}
