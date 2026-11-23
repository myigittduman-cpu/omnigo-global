import {labelMap,html,t,currentLocale} from './i18n.js';
import {selectedInboxStatus,inboxStatusFilters} from './inbox-status.js';
import {showConversation,conversationMarkup,bindConversation} from './inbox-conversation.js';
import {inboxQuestionProviderButtons,inboxQuestionFilters,inboxQuestionList,inboxQuestionPages,questionProviders,selectedQuestionProvider,selectedQuestionSort} from './inbox-question-list.js';
import {api} from './api.js';
import {loadingState,contentError} from './content-state.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {esc,toast} from './ui.js';
import {showFeedback} from './save-feedback.js';
import {copyTableText} from './table-copy-button.js';
import {bindQuestionReplies} from './inbox-row-reply.js';
import {preserveTablePosition,scrollTableStart} from './table-page-scroll.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {replyJobDetail,sourceAnsweredLabel} from './inbox-reply-delivery.js';

const styles=document.createElement('link');styles.rel='stylesheet';styles.href='/inbox.css';document.head.append(styles);
const labels=labelMap({pending:'Sırada',running:'İşleniyor',verifying:'Pazaryeri onayı bekleniyor',done:'Kontrol tamamlandı',sent:'Pazaryerine iletildi',accepted:'Pazaryerinde değerlendirmeye alındı',failed:'İşlem tamamlanamadı',blocked:'Bağlantı doğrulanamadı',unknown:'Gönderim doğrulanamadı',confirmed_sent:'Onaylandı',source_answered:sourceAnsweredLabel,cancelled:'İletilmediği kontrol edildi'});
const stamp=value=>value?new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}):t('Henüz kontrol edilmedi');
const hasDraft=state=>Boolean(state.inboxConversation?.draft?.trim()||state.inboxConversation?.files?.length||state.inboxConversation?.sending);
const hasKoctas=state=>state.data?.channels?.some(channel=>channel.name==='Koçtaş'&&channel.status==='active'&&channel.connection!=='not_connected');
const paramsFor=(state,company,page=state.inboxPage||0,presentation='table')=>new URLSearchParams({
 company,page,status:selectedInboxStatus(state),
 size:presentation==='table'?'10':'50',provider:selectedQuestionProvider(state),q:state.inboxSearch||'',
 sort:selectedQuestionSort(state),presentation,
});
const viewKey=state=>paramsFor(state,state.company).toString();
export const inboxNames={inbox:'Pazaryeri Soruları'};
export const isInbox=view=>Object.hasOwn(inboxNames,view);

export async function loadInbox(state){
 if(state.user?.role!=='admin'||!state.company||!selectedQuestionProvider(state))return false;
 const token=workspaceToken(state),key=viewKey(state),request=(state.inboxRequest||0)+1;
 state.inboxRequest=request;
 const current=()=>sameWorkspace(state,token)&&state.view==='inbox'&&viewKey(state)===key&&state.inboxRequest===request;
 const params=paramsFor(state,token.company);
 try{
  const data=await api('/inbox/notifications?'+params);
  if(!current())return false;
  if(!data.items.length&&(state.inboxPage||0)>0){
   state.inboxPage=Math.max(0,Math.ceil((data.total||0)/10)-1);
   return loadInbox(state);
  }
  state.inboxData=data;
  state.inboxError='';
  const open=state.inboxConversation;
  if(!open||hasDraft(state)||open.record.jobs.some(job=>['pending','running'].includes(job.state)))return true;
  const record=await api('/inbox/threads/'+open.record.id+'?company='+token.company);
  if(current()&&state.inboxConversation===open&&!hasDraft(state))open.record=record;
  return current();
 }catch(error){
  if(!current())return false;
  state.inboxError=error.message;
  if(error.status===401)throw error;
  return true;
 }
}

export const inboxPage=state=>html`<section class="marketplace-questions" data-marketplace-questions>
 <div data-inbox-list>${inboxContent(state)}</div>
 <dialog class="inbox-detail-panel" id="inbox-question-detail" aria-label="Soru ayrıntısı">
  <button type="button" class="text-btn inbox-dialog-close" data-close-inbox-dialog aria-label="Soru ayrıntısını kapat">×</button>
  <p class="inbox-dialog-warning form-error" data-inbox-dialog-warning role="alert" hidden></p>
  <div class="inbox-dialog-body" data-inbox-dialog-body></div>
 </dialog>
</section>`;

export function bindInboxPage(root,state){
 const host=root.querySelector('[data-marketplace-questions]');if(!host)return;
 const list=host.querySelector('[data-inbox-list]');
 host.addEventListener('error',event=>{
  if(event.target.matches?.('.inbox-product-image img'))event.target.hidden=true;
 },true);
 const token=workspaceToken(state);
 const current=()=>host.isConnected&&state.view==='inbox'&&sameWorkspace(state,token);
 const usingList=()=>host.contains(document.activeElement)&&document.activeElement?.matches('textarea,input,select')
  ||hasTableTextSelection(host)||hasDraft(state)||!!state.inboxOpening;
 const paint=()=>{
  if(!current())return;
  const next=inboxContent(state);
  if(host.inboxMarkup!==next){
   const restoreScroll=preserveTablePosition(list);
   const active=document.activeElement;
   const activeThread=host.contains(active)?active.dataset.inboxThread:null;
   const activeProvider=host.contains(active)?active.dataset.inboxProviderFilter:undefined;
   const activeReply=active?.closest('[data-inbox-row-reply]');
   const replyId=active?.matches('textarea')&&list.contains(activeReply)?activeReply.dataset.inboxRowReply:null;
   const selection=replyId?[active.selectionStart,active.selectionEnd,active.selectionDirection]:null;
   host.inboxMarkup=next;list.innerHTML=next;
   restoreScroll();
   if(activeThread)host.querySelector('[data-inbox-thread="'+activeThread+'"]')?.focus({preventScroll:true});
   if(activeProvider!==undefined)host.querySelector('[data-inbox-provider-filter="'+activeProvider+'"]')?.focus({preventScroll:true});
   if(replyId){
    const field=list.querySelector('[data-inbox-row-reply="'+replyId+'"] textarea:not(:disabled)');
    if(field){field.focus({preventScroll:true});field.setSelectionRange(...selection);}
   }
  }
  bindInbox(host,state,paint);
 };
 let pending;
 const refresh=({passive=false}={})=>{
  if(!current()||(passive&&(usingList()||sameWorkspace(state,state.inboxManualRefresh||{}))))return;
  if(pending)return pending;
  pending=loadInbox(state).then(applied=>{
   if(!applied||!current()||(passive&&usingList()))return false;
   paint();return true;
  })
   .finally(()=>{pending=null;});
  return pending;
 };
 host.inboxMarkup=inboxContent(state);
 host.inboxPaint=paint;
 host.inboxRefresh=()=>refresh({passive:true});
 host.pageRefresh=async()=>{
  if(sameWorkspace(state,state.inboxManualRefresh||{}))return false;
  if(!await refresh())return false;
  if(state.inboxError||state.inboxData?.available===false)throw new Error(state.inboxError||t('Soru listesi şu anda alınamıyor.'));
  return true;
 };
 bindInbox(host,state,paint);
 if(selectedQuestionProvider(state)==='koctas'&&hasKoctas(state))void scanInbox(state,false);
 void refresh().catch(error=>{if(current())toast(error.message);});
}

function inboxJobs(data,provider,open){
 if(!data||!provider)return '';
 const operation={reply:'Yanıt gönderimi',scan:'Soru aktarımı',detail:'Konuşma kontrolü',scheduled:'Soru aktarımı'};
 const jobs=(data.jobs||[]).filter(job=>job.provider===provider).slice(0,8).map(job=>{
  const detail=replyJobDetail(job.state,job.error);
  return html`<li><strong>${esc(job.provider)} · ${esc(t(operation[job.kind]||'Mesaj işlemi'))}</strong><span>${esc(labels[job.state]||job.state)} · ${esc(stamp(job.created))}</span>${detail?html`<p>${esc(detail)}</p>`:''}</li>`;
 }).join('');
 return html`<details class="inbox-jobs" ${open?'open':''}><summary>Son mesaj işlemleri</summary>${jobs?html`<ul>${jobs}</ul>`:html`<p class="inbox-empty">Bu pazaryerinde kayıtlı mesaj işlemi bulunmuyor.</p>`}</details>`;
}

export function inboxContent(state){
 const data=state.inboxData;
 const available=Boolean(selectedQuestionProvider(state));
 const shown=data?.items.length||0,start=shown?(state.inboxPage||0)*10+1:0,total=data?.total??shown;
 return html`<div class="inbox-controls">
  <div class="inbox-control-groups">${inboxStatusFilters(state)}${inboxQuestionProviderButtons(state)}</div>
  <button class="text-btn" type="button" data-inbox="local" ${!available||sameWorkspace(state,state.inboxManualRefresh||{})?'disabled':''}>Listeyi yenile</button>
 </div>
 ${state.inboxError?contentError(state.inboxError):''}
 <div class="inbox-workspace">
  <section class="inbox-list-panel" aria-label="Pazaryeri soruları listesi">
   <div class="inbox-list-tools"><p role="status">${shown?html`<strong>${start}–${start+shown-1}</strong> / ${total} kayıt`:available&&(data||!state.inboxError)?t(data?'0 kayıt':'Sorular yükleniyor…'):''}</p>
    <div class="inbox-toolbar">
     <button class="btn" type="button" data-inbox="download" ${!shown||state.inboxDownloading?'disabled':''}>${t(state.inboxDownloading?'Hazırlanıyor…':'İndir')}</button>
    </div>
   </div>
   ${inboxQuestionFilters(state)}
   <div class="inbox-list-scroll">${available?inboxQuestionList(data,state):html`<p class="inbox-empty" role="status">Bu mağazada soru bağlantısı bulunmuyor.</p>`}</div>
   ${inboxQuestionPages(data,state.inboxPage||0)}
  </section>
 </div>
 ${inboxJobs(data,questionProviders(state).find(([key])=>key===selectedQuestionProvider(state))?.[1],state.inboxJobsOpen)}`;
}

export async function scanInbox(state,notify=true){
 const token=workspaceToken(state);
 try{
  const result=await api('/inbox/scan?company='+token.company,'POST',{});
  if(!result.jobId)throw new Error(t('Koçtaş soru kontrolü sıraya alınamadı.'));
  if(notify&&sameWorkspace(state,token))showFeedback(t('Koçtaş soru kontrolü sıraya alındı.'),{kind:'pending'});
  return true;
 }catch(error){if(notify&&sameWorkspace(state,token))showFeedback(error.message,{kind:'error'});return error;}
}

async function refreshInboxManually(button,state,render,token){
 if(!selectedQuestionProvider(state)||sameWorkspace(state,state.inboxManualRefresh||{}))return;
 const key=viewKey(state);
 const current=()=>sameWorkspace(state,token)&&state.view==='inbox'&&viewKey(state)===key;
 state.inboxManualRefresh=token;
 button.disabled=true;
 let feedback;
 try{
  const scan=selectedQuestionProvider(state)==='koctas'&&hasKoctas(state)?await scanInbox(state,false):null;
  if(!current()||!await loadInbox(state)||!current())return;
  if(state.inboxError||state.inboxData?.available===false){
   feedback={message:state.inboxError||t('Soru listesi şu anda alınamıyor.'),kind:'error'};
  }else if(scan instanceof Error){
   feedback={message:scan.message,kind:'error'};
  }else if(scan===true){
   feedback={message:t('Liste yenilendi. Koçtaş soru kontrolü sıraya alındı.'),kind:'pending'};
  }else feedback={message:t('Liste yenilendi.'),kind:'success'};
 }catch(error){feedback={message:error.message,kind:'error'};}
 finally{
  if(state.inboxManualRefresh===token)state.inboxManualRefresh=null;
  if(button.isConnected)button.disabled=false;
  if(current()&&feedback){render();showFeedback(feedback.message,{kind:feedback.kind,force:true});}
 }
}
export async function conversation(id,readId,state,render){return showConversation(id,readId,state,render,loadInbox);}

function bindInboxDetail(root,state,render){
 const dialog=root.querySelector('#inbox-question-detail');
 if(!dialog){bindConversation(root,state,render,loadInbox);return;}
 const body=dialog.querySelector('[data-inbox-dialog-body]');
 const warning=dialog.querySelector('[data-inbox-dialog-warning]');
 const thread=state.inboxOpening||state.inboxConversation?.record.id;
 const detail=state.inboxOpening?loadingState('Soru ayrıntısı yükleniyor…',{compact:true}):
  state.inboxConversation?conversationMarkup(state.inboxConversation):'';
 if(detail!==body.inboxMarkup){
  const conversationTop=body.querySelector('.inbox-dialog-history')?.scrollTop;
  const active=document.activeElement;
  body.inboxMarkup=detail;
  body.innerHTML=detail;
  warning.hidden=true;
  warning.textContent='';
  const messages=body.querySelector('.inbox-dialog-history');
  if(messages&&conversationTop!==undefined)messages.scrollTop=conversationTop;
  if(active?.matches('[data-question-heading]'))body.querySelector('[data-question-heading]')?.focus({preventScroll:true});
 }
 if(thread&&!dialog.open)dialog.showModal();
 if(!thread&&dialog.open)dialog.close();
 bindConversation(root,state,render,loadInbox);
 if(!thread)return;
 const dismiss=()=>{
  if(hasDraft(state)){
   warning.textContent=t('Önce yanıtınızı gönderin veya taslağı temizleyin.');
   warning.hidden=false;
   return;
  }
  closeInboxDetail(root,state,render);
 };
 dialog.querySelector('[data-close-inbox-dialog]').onclick=dismiss;
 const back=body.querySelector('[data-close-conversation]');
 if(back)back.onclick=dismiss;
 dialog.oncancel=event=>{
  event.preventDefault();
  const expanded=dialog.querySelector('[data-reply-templates-toggle][aria-expanded=true]');
  if(expanded){expanded.click();expanded.focus({preventScroll:true});return;}
  dismiss();
 };
 const outside=event=>{
  if(event.target!==dialog)return false;
  const box=dialog.getBoundingClientRect();
  return event.clientX<box.left||event.clientX>=box.right||event.clientY<box.top||event.clientY>=box.bottom;
 };
 let pressedOutside=false;
 dialog.onpointerdown=event=>{pressedOutside=outside(event);};
 dialog.onclick=event=>{
  if(pressedOutside&&outside(event))dismiss();
  pressedOutside=false;
 };
}

export function bindInbox(root,state,render){
 bindInboxDetail(root,state,render);
 bindQuestionReplies(root,state,render,loadInbox);
 const history=root.querySelector('.inbox-jobs');
 if(history)history.ontoggle=()=>{state.inboxJobsOpen=history.open;};
 root.querySelectorAll('[data-inbox-message-copy],[data-inbox-product-copy]').forEach(button=>button.onclick=async()=>{
  const product=button.dataset.inboxProductCopy;
  try{await copyTableText(JSON.parse(product??button.dataset.inboxMessageCopy));toast(t(product?'Kopyalandı':'Mesaj kopyalandı.'));}
  catch(error){toast(error.message);}
 });
 const form=root.querySelector('[data-inbox-filters]');
 if(!form)return;
 const apply=async()=>{
  if(hasDraft(state)){toast(t('Önce yanıtınızı gönderin veya taslağı temizleyin.'));return;}
  const token=workspaceToken(state);
  const previousProvider=selectedQuestionProvider(state);
  state.inboxSearch=form.elements.q.value.trim();
  state.inboxProvider=form.elements.provider.value;
  state.inboxSort=form.elements.sort.value;
  state.inboxPage=0;state.inboxConversation=null;state.inboxOpening=null;
  if(previousProvider!=='koctas'&&selectedQuestionProvider(state)==='koctas'&&hasKoctas(state))void scanInbox(state,false);
  try{if(await loadInbox(state)&&sameWorkspace(state,token)){
   render();root.querySelector('[data-inbox-filters] [type="submit"]')?.focus({preventScroll:true});
  }}
  catch(error){toast(error.message);}
 };
 form.onsubmit=event=>{event.preventDefault();void apply();};
}

function closeInboxDetail(root,state,render){
 if(hasDraft(state)){toast(t('Önce yanıtınızı gönderin veya taslağı temizleyin.'));return;}
 const thread=state.inboxOpening||state.inboxConversation?.record.id;
 state.inboxOpening=null;state.inboxConversation=null;render();
 if(thread)root.querySelector('[data-inbox-thread="'+thread+'"]')?.focus({preventScroll:true});
}

async function downloadInbox(state,render){
 if(state.inboxDownloading||!state.inboxData?.items.length)return;
 const token=workspaceToken(state),key=viewKey(state);
 const current=()=>state.view==='inbox'&&sameWorkspace(state,token)&&viewKey(state)===key;
 state.inboxDownloading=true;render();
 try{
  const {downloadInboxWorkbook}=await import('./inbox-question-download.js');
  if(current())await downloadInboxWorkbook(paramsFor(state,token.company,0,'download'),current,workspaceExportCompany(state,token.company));
 }
 finally{if(sameWorkspace(state,token)){state.inboxDownloading=false;render();}}
}

export async function inboxClick(el,state,render){
 if(state.user?.role!=='admin')return;
 try{
  if(hasDraft(state)){toast(t('Önce yanıtınızı gönderin veya taslağı temizleyin.'));return;}
  if(el.dataset.inboxThread){await conversation(Number(el.dataset.inboxThread),Number(el.dataset.inboxRead)||null,state,render);return;}
  const action=el.dataset.inbox,token=workspaceToken(state);
  if(!action)return;
  if(action==='local'){await refreshInboxManually(el,state,render,token);return;}
  if(action==='close-detail'){const root=el.closest('[data-marketplace-questions]');if(root)closeInboxDetail(root,state,render);return;}
  if(action==='download'){await downloadInbox(state,render);return;}
  if(action==='provider'&&(!questionProviders(state).some(([key])=>key===el.dataset.inboxProviderFilter)
   ||selectedQuestionProvider(state)===el.dataset.inboxProviderFilter&&selectedInboxStatus(state)==='waiting'))return;
  if(['page','status','provider'].includes(action)){state.inboxConversation=null;state.inboxOpening=null;}
  if(action==='provider'){state.inboxProvider=el.dataset.inboxProviderFilter;state.inboxStatus='waiting';state.inboxPage=0;}
  if(action==='status'){state.inboxStatus=el.dataset.inboxStatus;state.inboxPage=0;}
  if(action==='page')state.inboxPage=Math.max(0,Number(el.dataset.inboxPage)||0);
  if(action==='provider'&&selectedQuestionProvider(state)==='koctas'&&hasKoctas(state))void scanInbox(state,false);
  const root=el.closest('[data-marketplace-questions]');
  const applied=await loadInbox(state);
  if(!applied||!sameWorkspace(state,token))return;
  render();
  if(action==='page'){
   scrollTableStart(root,'.inbox-question-table');
   root?.querySelector('.inbox-pagination [aria-current=page]')?.focus({preventScroll:true});
  }
 }catch(error){toast(error.message);}
}
