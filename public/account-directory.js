import {api} from './api.js';
import {scrollTableStart} from './table-page-scroll.js';
import {t} from './i18n.js';
import {accountDirectoryRows} from './account-directory-view.js';
import {accountDirectoryPagination} from './account-directory-pagination.js';
import {openAccountDirectoryEditor} from './account-directory-editor.js';
import {bindInlineAccountCreation} from './account-directory-create.js';
import {createCustomerAccount} from './customer-account-form.js';
export function bindAccountDirectory(root,state,{current,openChild,drafts,company,onUpdated=()=>{},onAccountUpdated=()=>{},onReady}){
 if(state.user?.role!=='admin'||!root)return;
 const filters=root.querySelector('form'),status=root.querySelector('[role=status]');
 const tabs=[...root.querySelectorAll('[data-directory-tab]')];
 const table=root.querySelector('[data-directory-list]'),pages=root.querySelector('[data-directory-pages]');
 if(company){
  filters.elements.company.replaceChildren(new Option(company.name,String(company.id)));
  filters.elements.company.disabled=true;
 }
 let role='client',page=1,data=null,request=0,timer;
 const valid=()=>root.isConnected&&current();
 async function refresh({scrollToTable=false}={}){
  if(!valid())return;const sequence=++request,values=Object.fromEntries(new FormData(filters));status.textContent=t('Kullanıcılar yükleniyor…');
  if(company&&role==='client')values.company=String(company.id);
  try{const result=await api('/admin/users?'+new URLSearchParams({...values,role,page,pageSize:3}));if(!valid()||sequence!==request)return;data=result;page=result.page;table.innerHTML=accountDirectoryRows(result);status.textContent=t('Güncel hesaplar gösteriliyor.');pages.innerHTML=accountDirectoryPagination(result);
   if(!company){const selected=filters.elements.company.value;filters.elements.company.replaceChildren(new Option(t('Tüm mağazalar'),''),...result.companies.map(c=>new Option(c.name,String(c.id))));filters.elements.company.value=selected;}
   if(scrollToTable)scrollTableStart(root,'[data-directory-list] table');
   if(onReady){const ready=onReady;onReady=null;ready();}
  }catch(error){if(valid()&&sequence===request)status.textContent=error.message;}
 }
 async function selectRole(next,{focus=false,reload=false}={}){
  if(!valid()||!tabs.some(tab=>tab.dataset.directoryTab===next))return;
  if(next===role){if(reload)await refresh();return;}
  clearTimeout(timer);role=next;page=1;data=null;
  for(const tab of tabs){
   const active=tab.dataset.directoryTab===role;
   tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;
   if(active&&focus)tab.focus();
  }
  root.querySelector('#directory-panel').setAttribute('aria-labelledby','directory-tab-'+role);
  filters.querySelector('[data-directory-store-filter]').hidden=role==='admin';
  filters.elements.company.disabled=Boolean(company)||role==='admin';
  table.replaceChildren();pages.replaceChildren();
  await refresh();
 }
 root.querySelector('[role=tablist]').onkeydown=event=>{
  if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
  event.preventDefault();
  const next=event.key==='Home'?'client':event.key==='End'?'admin':role==='client'?'admin':'client';
  void selectRole(next,{focus:true});
 };
 const saved=async user=>{onUpdated();if(user&&user.id===state.user.id)await onAccountUpdated(user);};
 const create=bindInlineAccountCreation(root,{drafts,current:valid,fixedAccountType:company?'admin':null,onSaved:async accountType=>{onUpdated();await selectRole(accountType,{reload:true});}});
 filters.onsubmit=event=>event.preventDefault();filters.oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>{page=1;void refresh();},250);};filters.onchange=()=>{clearTimeout(timer);page=1;void refresh();};
 root.onclick=event=>{const button=event.target.closest('button');if(!button||!valid())return;
  if(button.dataset.directoryTab){void selectRole(button.dataset.directoryTab);return;}
  if(button.hasAttribute('data-directory-create')){
   if(company&&role==='client')void openChild(options=>createCustomerAccount(state,onUpdated,{companyId:company.id,...options,onDone:async()=>{await refresh();await options.onDone();}}),button,t('Yeni kullanıcı ekle'));
   else create(company?[company]:(data?.companies||state.companies),role);
  }
  const id=Number(button.dataset.directoryEdit),user=data?.items.find(u=>u.id===id);
  if(button.hasAttribute('data-directory-edit')&&user)void openChild(options=>openAccountDirectoryEditor(user,data.companies,{current,onSaved:saved,...options,onDone:async()=>{await refresh();await options?.onDone?.();}}),button,t('Kullanıcıyı düzenle')+' · '+user.name);
  if(button.hasAttribute('data-directory-goto')&&!button.disabled){const next=Number(button.dataset.directoryGoto);if(Number.isInteger(next)&&next>=1&&next<=data?.pageCount&&next!==page){page=next;void refresh({scrollToTable:true});}}
 };
 const interval=setInterval(()=>{if(!current()){clearInterval(interval);clearTimeout(timer);return;}if(root.isConnected&&!root.hidden&&!root.querySelector('[data-directory-index]')?.hidden&&!document.hidden&&!root.contains(document.activeElement)&&document.querySelectorAll('dialog[open]').length===1)void refresh();},15000);
 const dialog=root.closest('dialog');
 const dispose=()=>{clearInterval(interval);clearTimeout(timer);request++;dialog?.removeEventListener('close',dispose);};
 dialog?.addEventListener('close',dispose,{once:true});void refresh();
 return dispose;
}
