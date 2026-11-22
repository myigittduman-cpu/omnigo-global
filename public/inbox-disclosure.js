import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
// Disclosure state belongs to the current workspace/page, never persistent storage.
let binding;
export function setInboxExpanded(button,state,expanded){
 const panel=button.closest('.inbox-marketplace');
 const list=panel?.querySelector('.inbox-more-items');
 if(!list)return;
 state.inboxExpanded??=new Set();
 const name=button.dataset.inboxProvider;
 if(expanded)state.inboxExpanded.add(name);else state.inboxExpanded.delete(name);
 list.hidden=!expanded;button.setAttribute('aria-expanded',String(expanded));
 button.textContent=expanded?i18nT('Daha az göster'):i18nText`Tamamını gör (${Number(button.dataset.inboxRemaining)} daha)`;
}
export function bindInboxDisclosure(root,state){
 binding?.abort();binding=new AbortController();
 if(state.user?.role!=='admin'||state.view!=='inbox')return;
 // Use completed clicks so collapsing a long panel cannot retarget a pointer release.
 document.addEventListener('click',event=>{
  if(event.button!==0)return;
  for(const button of root.querySelectorAll('[data-inbox="toggle"][aria-expanded="true"]')){
   if(!button.closest('.inbox-marketplace').contains(event.target))setInboxExpanded(button,state,false);
  }
 },{capture:true,signal:binding.signal});
}
