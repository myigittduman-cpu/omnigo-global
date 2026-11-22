import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Only the notification choice is stored here. Device credentials remain native.
const choices=new Map();
const keyFor=current=>`omnigo-mobile-permission:${current.appId}:${current.installationId}`;
export function permissionChoice(current){
 const key=keyFor(current);
 try{const value=localStorage.getItem(key);if(value==='allowed'||value==='declined')return value;}catch{}
 return choices.get(key)||null;
}
export function rememberPermission(current,value){
 const key=keyFor(current);choices.set(key,value);
 try{localStorage.setItem(key,value);}catch{}
}
export function showPermissionError(message){
 if(typeof document==='undefined')return;
 document.getElementById('mobilePermissionError')?.remove();
 const notice=document.createElement('aside');notice.id='mobilePermissionError';notice.className='mobile-permission-error';notice.setAttribute('role','status');
 const text=document.createElement('p');text.textContent=i18nT('Bildirimler henüz açılmadı. ')+message;
 const close=document.createElement('button');close.type='button';close.textContent=i18nT('Kapat');close.onclick=()=>notice.remove();
 notice.append(text,close);document.body.append(notice);
}
export function askMobilePermission(signal){
 if(signal.aborted)return Promise.resolve(null);
 if(!document.getElementById('mobilePermissionStyle')){
  const link=document.createElement('link');link.id='mobilePermissionStyle';link.rel='stylesheet';link.href='/mobile-permission.css';document.head.append(link);
 }
 return new Promise(resolve=>{
  const dialog=document.createElement('dialog');dialog.className='mobile-permission';
  dialog.setAttribute('aria-labelledby','mobilePermissionTitle');dialog.setAttribute('aria-describedby','mobilePermissionDescription');
  dialog.innerHTML=i18nHtml('<div class="mobile-permission-mark" aria-hidden="true">')+
   i18nHtml('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></div>')+
   i18nHtml('<h2 id="mobilePermissionTitle">Bildirimlere izin ver</h2>')+
   i18nHtml('<p id="mobilePermissionDescription">Hesabınıza ait bildirimleri uygulama açıkken ve kapalıyken sesli alın. Bağlantı bildirimleri yalnız panelde görünür.</p>')+
   i18nHtml('<div class="mobile-permission-actions"><button type="button" data-permission-allow>Bildirimlere izin ver</button><button type="button" data-permission-decline>İzin verme</button></div>')+
   i18nHtml('<p class="mobile-permission-note">Tercihinizi daha sonra Bildirim ayarlarından değiştirebilirsiniz.</p>');
  let finished=false;
  const finish=value=>{if(finished)return;finished=true;signal.removeEventListener('abort',cancel);dialog.close();dialog.remove();resolve(value);};
  const cancel=()=>finish(null);
  dialog.querySelector('[data-permission-allow]').onclick=()=>finish('allowed');
  dialog.querySelector('[data-permission-decline]').onclick=()=>finish('declined');
  dialog.addEventListener('cancel',event=>{event.preventDefault();finish('declined');});
  dialog.addEventListener('close',()=>finish('declined'));
  signal.addEventListener('abort',cancel,{once:true});
  document.body.append(dialog);dialog.showModal();
 });
}
