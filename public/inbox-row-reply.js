import {confirmAction} from './action-confirmation.js';
import {api} from './api.js';
import {html,t} from './i18n.js';
import {activeReplyState,enqueueInboxReply,replyJobDetail,replyRequestId,sourceAnsweredLabel,watchReplyDelivery} from './inbox-reply-delivery.js';
import {hasReplyPlaceholder} from './inbox-reply-templates.js';
import {esc} from './ui.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';

const labels={
 submitting:'Gönderiliyor',pending:'Gönderim sırada',running:'Gönderiliyor',verifying:'Pazaryeri onayı bekleniyor',
 sent:'Pazaryerine iletildi',confirmed_sent:'Pazaryerine iletildi',accepted:'Pazaryerinde değerlendirmeye alındı',
 failed:'Gönderilemedi',unknown:'Gönderim doğrulanamadı',cancelled:'İletilmediği doğrulandı',rejected:'Reddedildi',source_answered:sourceAnsweredLabel,
};
const busy=phase=>phase==='submitting'||activeReplyState(phase);
const locked=phase=>busy(phase)||['unknown','accepted','sent','confirmed_sent','source_answered'].includes(phase);
const retryable=phase=>phase==='failed'||phase==='cancelled';
const latestInbound=record=>record.messages?.findLast(message=>message.sender==='CUSTOMER_USER')?.localId;
export const rowCanReply=row=>row.replyState!=='source_answered'&&(row.canReply??(row.status==='waiting'&&['Koçtaş','Pazarama'].includes(row.provider)));

export function questionReplyStatus(phase,error){
 if(!Object.hasOwn(labels,phase))return '';
 const problem=retryable(phase)||phase==='unknown'||phase==='rejected';
 const badge=busy(phase)?'pending':['sent','confirmed_sent'].includes(phase)?'confirmed':problem?'problem':'';
 const detail=replyJobDetail(phase,error);
 return html`<span class="inbox-row-reply-result" role="status" data-reply-state="${phase}" ${detail?html`title="${esc(detail)}"`:''}>
  <strong class="inbox-reply-badge ${badge?`inbox-reply-badge--${badge}` : ''}">${esc(t(labels[phase]))}</strong>
 </span>`;
}

export function lockedReplyText(body){
 return html`<div class="inbox-locked-reply" role="region" tabindex="0" aria-label="Gönderilen yanıt">${esc(body)}</div>`;
}

function replyDraft(state,row){
 const draft=state.inboxRowReplies?.get(row.id);
 if(!draft?.followingList||!row.replyState||row.replyState===draft.previousServerPhase)return draft;
 return {...draft,phase:row.replyState,canReply:row.canReply,error:'',followingList:false};
}

export function questionReplyForm(row,state,detailButton,deadline){
 const draft=row.canMarkRead===false?undefined:replyDraft(state,row);
 const phase=row.status==='rejected'?row.replyState:draft?.phase||row.replyState;
 const editable=draft?.canReply??rowCanReply(row);
 const hasEditor=row.status==='waiting'&&editable&&!locked(phase);
 const error=draft?.error;
 const status=row.status==='answered'?questionReplyStatus('sent'):questionReplyStatus(phase,error||row.replyError);
 const detail=detailButton?html`<div class="inbox-row-action-line">${detailButton}</div>`:'';
 if(!hasEditor)return html`<div class="inbox-row-actions">
  ${row.status==='answered'?status:''}
  ${row.status!=='answered'&&(row.replyBody||draft?.body)?lockedReplyText(row.replyBody??draft.body):''}
  ${error?html`<p class="form-error" role="alert">${esc(error)}</p>`:''}
  <div class="inbox-row-reply-footer">${deadline}
   ${row.status!=='answered'&&status?html`<div class="inbox-row-action-line">${status}${detailButton}</div>`:detail}
  </div>
 </div>`;
 return html`<form class="inbox-row-actions inbox-row-editor" data-inbox-row-reply="${row.id}">
  <textarea name="body" rows="3" maxlength="2000" ${row.provider==='Trendyol'?'minlength="10"':''} aria-label="Yanıtınız" placeholder="Yanıtınızı yazın…" required>
${esc(draft?.body??'')}</textarea>
  ${error?html`<p class="form-error" role="alert">${esc(error)}</p>`:''}
  ${retryable(phase)?status:''}
  <div class="inbox-row-reply-footer">
   ${deadline}
   <div class="inbox-row-action-line">
    <button class="btn" type="submit">Yanıtla</button>
    ${detailButton}
   </div>
  </div>
 </form>`;
}

export function bindQuestionReplies(root,state,render,reload){
 if(state.user?.role!=='admin')return;
 const drafts=state.inboxRowReplies??=new Map();
 const token=workspaceToken(state);
 const current=()=>state.view==='inbox'&&sameWorkspace(state,token);
 const paint=()=>{if(current()){
  const activeHost=document.querySelector('[data-marketplace-questions]');
  if(activeHost?.inboxPaint)activeHost.inboxPaint();
  else if(root.isConnected)render();
 }};
 const refresh=async()=>{
  if(!current())return;
  await reload(state);
  if(current()){paint();window.dispatchEvent(new Event('omnigo-inbox-read'));}
 };
 for(const form of root.querySelectorAll('[data-inbox-row-reply]')){
  const id=Number(form.dataset.inboxRowReply),field=form.elements.body;
  const currentRow=()=>state.inboxData?.items.find(item=>item.id===id);
  const draftForRow=row=>replyDraft(state,row)||{body:'',phase:'',error:''};
  field.oninput=()=>{
   const row=currentRow();
   if(!row||!current()||field.disabled)return;
   const draft=draftForRow(row);
   if(locked(draft.phase))return;
   drafts.set(id,{...draft,body:field.value,error:''});
   form.querySelector('[role=alert]')?.remove();
  };
  form.onsubmit=async event=>{
   event.preventDefault();
   const row=currentRow(),body=field.value.trim();
   if(!row||!current()||!body||field.disabled||!form.reportValidity())return;
   const draft=draftForRow(row);
   if(locked(draft.phase))return;
   draft.body=field.value;draft.error='';drafts.set(id,draft);
   if(hasReplyPlaceholder(body)){
    draft.error=t('Göndermeden önce şablondaki il veya ilçe bilgisini doldurun.');paint();return;
   }
   const previousPhase=draft.phase;
   Object.assign(draft,{phase:'submitting',previousServerPhase:row.replyState,followingList:false});paint();
   const scope='company='+token.company;
   const requestId=replyRequestId();
   let watching=false,posted=false;
   const follow=jobId=>{
    watching=true;
    const apply=record=>{
     const job=record.jobs?.find(item=>item.id===jobId&&item.kind==='reply');
     if(!job)return;
     Object.assign(draft,{phase:job.state,canReply:record.canReply,error:'',followingList:job.state==='unknown'});
     if(['sent','confirmed_sent','source_answered','accepted'].includes(job.state))draft.body='';
     paint();
    };
    watchReplyDelivery(state,row.threadId,jobId,{
     notify:false,trackActiveStates:true,onProgress:apply,
     async onUpdate(record){apply(record);await refresh();},
     onTimeout(){
      draft.phase='unknown';draft.followingList=true;
      draft.error=t('Gönderim doğrulanamadı. Pazaryerindeki konuşmayı kontrol edin.');paint();
      void refresh().catch(()=>{});
     },
    });
   };
   try{
    if(!await confirmAction(t('Mesajı göndermek istiyor musunuz?'),{focusApprove:true})||!current()||!currentRow())return;
    const record=await api(`/inbox/threads/${row.threadId}?${scope}`,'GET',undefined,{signal:AbortSignal.timeout(12_000)});
    if(!current()||!currentRow())return;
    if(Number(record.companyId)!==token.company||Number(record.id)!==row.threadId||Number(latestInbound(record))!==id||!record.canReply){
     draft.canReply=false;
     throw new Error(t('Konuşma değişti. Yeni mesajları inceleyin.'));
    }
    posted=true;
    const result=await enqueueInboxReply(scope,row.threadId,body,record.data.fingerprint,requestId,[],AbortSignal.timeout(15_000));
    if(!sameWorkspace(state,token))return;
    if(!result.jobId)throw new Error(t('Gönderim onayı alınamadı. Sonucu konuşmadan kontrol edin.'));
    follow(result.jobId);
    draft.phase='pending';paint();void refresh().catch(()=>{});
    if(row.unread)void api(`/inbox/notifications/${row.id}/read?${scope}`,'POST',{}).catch(()=>{});
   }catch(error){
    if(!sameWorkspace(state,token))return;
    if(posted&&(!error.status||error.status>=500)){
     follow(requestId);
     draft.phase='verifying';
    }else{
     draft.phase='failed';draft.error=error.message;
    }
   }finally{
    if(!watching&&draft.phase==='submitting')draft.phase=previousPhase;
    paint();
   }
  };
 }
}
