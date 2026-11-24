import {installDialogDismiss} from './dialog-dismiss.js';
import './surface-motion.js';
import {revealContent} from './motion.js';
if(typeof document!=='undefined'&&!document.querySelector('link[data-transition-policy]')){
 const style=document.createElement('link');style.rel='stylesheet';style.href='/transition-policy.css';style.dataset.transitionPolicy='';document.head.append(style);
}
installDialogDismiss();
import {t as i18nT,text as i18nText,html as i18nHtml,currentLocale} from './i18n.js';
import {marketplaceLogo} from './marketplace-branding.js';
import {watchDialogDrafts} from './dialog-drafts.js';
import {isDialogCancel} from './dialog-cancel.js';
import {showFeedback} from './save-feedback.js';
import {resetPreservedDialogSize} from './dialog-view.js';
export const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export function initials(name){
 const words=String(name??'').trim().split(/\s+/u).filter(Boolean);
 return (words.length?[words[0],...(words.length>1?[words.at(-1)]:[])].map(word=>Array.from(word)[0]).join(''):'?').toLocaleUpperCase('tr-TR');
}
export const money=cents=>new Intl.NumberFormat(currentLocale(),{style:'currency',currency:'TRY',maximumFractionDigits:2}).format((cents||0)/100);
export const date=value=>value?new Date(value+'T12:00:00').toLocaleDateString(currentLocale(),{day:'numeric',month:'long',year:'numeric'}):'—';
export const now=()=>new Date().toLocaleDateString('sv-SE',{timeZone:'Europe/Istanbul'});
const paths={store:'M3 10l2-5h14l2 5v2a2 2 0 0 1-4 0 2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0 2 2 0 0 1-4 0zM5 14v6h14v-6M10 20v-5h4v5',inventory:'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',shipments:'M3 5h11v12H3zM14 9h4l3 4v4h-7M5 17a2 2 0 1 0 4 0M16 17a2 2 0 1 0 4 0',analytics:'M3 3v18h18M6 15l4-5 4 3 6-8',connections:'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2',customers:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8M17 4a4 4 0 0 1 0 7M22 21v-2a4 4 0 0 0-3-3.87',more:'M4 6h16M4 12h16M4 18h16',support:'M4 4h16v12H9l-5 4V4M8 8h8M8 12h5',inbox:'M3 4h18v14H8l-5 3V4M7 8h10M7 12h7',notifications:'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M9 21h6',overview:'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',channels:'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0M2 12h20M12 2c-7 5-7 15 0 20M12 2c7 5 7 15 0 20',payments:'M4 5h16v16H4zM7 3v4m10-4v4M4 10h16M8 14h2m4 0h2',tasks:'M3 7h18v14H3zM8 7V3h8v4M3 12c6 3 12 3 18 0',reports:'M14 3H5v18h14V8zM14 3v5h5M8 12h8M8 16h6',arrow:'M5 19 19 5M5 5h14v14',plus:'M12 5v14M5 12h14',logout:'M9 4H4v16h5M9 12h12m-5-5 5 5-5 5'};
export const icon=name=>i18nHtml`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name]||paths.reports}"/></svg>`;
export const platform=name=>i18nHtml`<span class="channel-identity">${marketplaceLogo(name)||i18nHtml`<strong>${esc(name)}</strong>`}</span>`;
export const labels={pending:'Bekleniyor',sent:'Aktarım bildirildi',paid:'Hesaba geçti',done:'Tamamlandı',running:'Devam ediyor',planned:'Planlandı',draft:'Taslak',published:'Yayımlandı',active:'Aktif',inactive:'Pasif'};
export const badge=status=>i18nHtml`<span class="badge ${esc(status)}">${i18nT(labels[status])||esc(status)}</span>`;
export const empty=(title,description)=>i18nHtml`<div class="empty-state">${icon('reports')}<h3>${esc(title)}</h3><p>${esc(description)}</p></div>`;
export function toast(message){showFeedback(message);}
const dialogCloseHandlers=new WeakMap();
export function openDialog(title,content,{className='',draftGuard,loading=false}={}){
 const dialog=document.getElementById('dialog'),prior=document.activeElement,wasOpen=dialog.open;
 if(!wasOpen)resetPreservedDialogSize(dialog);
 // Keep the previous size through its exit transition; reset only for the next window.
 dialog.className=className;
 dialog.innerHTML=i18nHtml`<div class="dialog-head"><h2 id="dialogTitle">${esc(title)}</h2><button class="close" aria-label="Kapat">×</button></div><div class="dialog-body">${content}</div>`;
 const drafts=watchDialogDrafts(dialog,title,{guard:draftGuard});
 const dismiss=async()=>{if(await drafts.canLeave()&&dialog.dispatchEvent(new Event('omnigo-dialog-dismiss',{cancelable:true})))dialog.close();};
 dialog.querySelector('.close').onclick=dismiss;
 dialog.oncancel=event=>{if(!isDialogCancel(event,dialog))return;event.preventDefault();void dismiss();};
 let startedOutside=false;
 const isOutside=event=>{
  if(event.target!==dialog)return false;
  const box=dialog.getBoundingClientRect();
  return event.clientX<box.left||event.clientX>box.right||event.clientY<box.top||event.clientY>box.bottom;
 };
 dialog.onpointerdown=event=>{startedOutside=event.button===0&&isOutside(event);};
 dialog.onpointercancel=()=>{startedOutside=false;};
 dialog.onclick=event=>{const outside=startedOutside&&isOutside(event);startedOutside=false;if(outside)void dismiss();};
 const oldClose=dialogCloseHandlers.get(dialog);if(oldClose)dialog.removeEventListener('close',oldClose);
 const onClose=()=>{if(dialog.open)return;startedOutside=false;dialog.removeEventListener('close',onClose);dialogCloseHandlers.delete(dialog);if(prior?.isConnected)prior.focus({preventScroll:true});};dialogCloseHandlers.set(dialog,onClose);dialog.addEventListener('close',onClose);
 dialog.showModal();if(wasOpen)revealContent(dialog.querySelector('.dialog-body'));
 if(!loading)requestAnimationFrame(()=>{if(dialog.open)document.dispatchEvent(new Event('omnigo-dialog-ready'));});
 return dialog;
}
