import {labelMap as localizedLabels} from './i18n.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,html as i18nHtml} from './i18n.js';
import {api,upload} from './api.js';
import {esc,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {activeReplyState,enqueueInboxReply,replyJobDetail,replyRequestId as nonce,sourceAnsweredLabel,watchReplyDelivery} from './inbox-reply-delivery.js';
import {confirmAction} from './action-confirmation.js';
import {bindReplyTemplates,hasReplyPlaceholder,replyTemplateMarkup,replyTemplates} from './inbox-reply-templates.js';

import {inboxMessageCopyButton,inboxQuestionMetadata} from './inbox-question-list.js';
import {lockedReplyText} from './inbox-row-reply.js';
export {inboxStatuses} from './inbox-status.js';
const labels=localizedLabels({pending:'Gönderim sırada',running:'Gönderiliyor',verifying:'Pazaryeri onayı bekleniyor',done:'Güncellendi',sent:'Pazaryerine iletildi',accepted:'Pazaryerinde değerlendirmeye alındı',failed:'Gönderilemedi',unknown:'Gönderim kontrol bekliyor',confirmed_sent:'Onaylandı',source_answered:sourceAnsweredLabel,cancelled:'İletilmediği doğrulandı'});
const stamp=value=>new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'});
let refreshTimer;
export function conversationMarkup(conversation){
 if(!conversation)return '';
 const {record,draft='',files=[]}=conversation,pazarama=record.provider==='Pazarama';
 const pending=record.jobs.some(job=>job.kind==='reply'&&(activeReplyState(job.state)||job.state==='unknown'));
 const readOnly=!(record.replySupported??['Koçtaş','Pazarama'].includes(record.provider));
 const canAttach=record.attachmentsSupported??record.provider==='Koçtaş';
 const fallbackCanReply=!record.data.partial&&(pazarama?(record.data.questionStatus===0&&record.data.localReplyState!=='sent'):record.data.recipients.length===1);
 const blocked=readOnly||pending||!(record.canReply??fallbackCanReply);
 const lockedBody=blocked?record.replyBody:'';
 const messages=record.messages.map(message=>i18nHtml`<article class="inbox-message ${message.sender==='SHOP_USER'?'outgoing':''}"><strong>${message.sender==='SHOP_USER'?i18nT('Mağaza'):message.sender==='CUSTOMER_USER'?i18nT('Müşteri'):i18nT('Pazaryeri')}</strong><time>${message.dateUnavailable?i18nT('Tarih bilgisi iletilmedi'):esc(message.dateOnly?new Date(message.created).toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul'}):stamp(message.created))}</time><p>${esc(message.body)}${message.sender==='SHOP_USER'?'':inboxMessageCopyButton(message.body)}</p>${message.attachments?i18nHtml('<small>Bu mesajda ek dosya var.</small>'):''}</article>`).join('');
 const jobs=record.jobs.filter(job=>job.kind==='reply').slice(0,3).map(job=>{
  const detail=replyJobDetail(job.state,job.error);
  return i18nHtml`<li data-reply-state="${esc(job.state)}"><strong>${esc(labels[job.state]||job.state)}</strong>${detail?i18nHtml`<p>${esc(detail)}</p>`:''}${record.resolvableReplyId===job.id?i18nHtml`<details><summary>Gönderimi kontrol ettim</summary><form data-resolve-inbox-job="${esc(job.id)}"><label>Kontrol sonucu<select name="resolution"><option value="confirmed_sent">Yanıt pazaryerinde görünüyor</option><option value="cancelled">Yanıt iletilmemiş</option></select></label><label><input name="checked" type="checkbox" required> Pazaryerindeki konuşmayı kontrol ettim.</label><button class="btn" type="submit">Sonucu kaydet</button><p class="form-error"></p></form></details>`:''}</li>`;
 }).join('');
 return i18nHtml`<section class="inbox-inline" data-inline-conversation="${record.id}" aria-label="Konuşma ve yanıt">
  <div class="inbox-inline-heading"><button class="text-btn" type="button" data-close-conversation>Geri</button><button class="text-btn" type="button" data-refresh-conversation ${readOnly||record.replyState==='rejected'?'hidden':''}>Konuşmayı güncelle</button>
   ${record.canReviewRejection&&!record.resolvableReplyId?i18nHtml`<button class="btn" type="button" data-review-inbox-rejection>İncelemeyi tamamla</button>`:''}
  </div>
  <div class="inbox-dialog-history" tabindex="0" role="region" aria-label="Konuşma geçmişi">
   ${inboxQuestionMetadata(record)}
   ${record.replyState==='rejected'?i18nHtml`<p class="form-error">Yanıt pazaryerinde reddedildi.</p>`:''}
   <div class="inbox-conversation">${messages}</div>
   ${readOnly?i18nHtml('<p class="delivery-note">Yanıtınızı pazaryeri panelinden gönderebilirsiniz.</p>'):''}
   ${jobs?i18nHtml`<ul class="inbox-job-list" aria-live="polite">${jobs}</ul>`:''}
  </div>
  <form class="form-grid inbox-inline-reply" data-inline-reply ${readOnly?'hidden':''}>
   <label>Yanıtınız<textarea name="body" rows="3" maxlength="2000" ${record.provider==='Trendyol'?'minlength="10"':''} required ${blocked?'disabled':''} ${lockedBody?'hidden':''}>
${esc(blocked?'':draft)}</textarea>${lockedBody?lockedReplyText(lockedBody):''}</label>
   ${files.length?i18nHtml`<ul class="inbox-attachment-list">${files.map(file=>i18nHtml`<li>${esc(file.name)}<button type="button" class="text-btn" data-remove-attachment="${esc(file.id)}" aria-label="${esc(file.name)} dosyasını kaldır">×</button></li>`).join('')}</ul>`:''}
   <p class="form-error" role="alert">${esc(conversation.error||'')}</p>
   <div class="inbox-reply-actions">
    ${canAttach?i18nHtml`<div class="inbox-attachment-actions"><label class="btn inbox-attach" title="En fazla 3 dosya · Her biri 2 MB">Görsel / PDF ekle<input type="file" name="attachments" accept="image/png,image/jpeg,application/pdf" multiple ${blocked?'disabled':''}></label></div>`:''}
    <button type="submit" class="btn primary" ${blocked?'disabled':''}>${pending?i18nT('Gönderim kontrol ediliyor'):i18nT('Yanıtı gönder')}</button>
   </div>
  </form>
  ${blocked?'':replyTemplateMarkup(conversation.replyTemplates||[],conversation.templatesOpen)}
 </section>`;
}
export async function showConversation(id,readId,state,render,reload){
 const token=workspaceToken(state),request=Symbol();
 state.inboxOpening=id;state.inboxOpenRequest=request;render();
 const current=()=>state.view==='inbox'&&sameWorkspace(state,token)&&state.inboxOpenRequest===request&&state.inboxOpening===id;
 try{
  const record=await api(`/inbox/threads/${id}?company=${token.company}`);
  if(!current())return;
  state.inboxOpening=null;state.inboxConversation={record,draft:'',files:[],requestId:nonce(),replyTemplates:replyTemplates(state)};render();
  document.querySelector('[data-question-heading]')?.focus({preventScroll:true});
  if(readId){
   await api(`/inbox/notifications/${readId}/read?company=${token.company}`,'POST',{});
   if(state.view==='inbox'&&sameWorkspace(state,token)){
    await reload(state);window.dispatchEvent(new Event('omnigo-inbox-read'));
    if(state.inboxConversation?.record.id===id&&!state.inboxConversation.draft)render();
   }
  }
 }catch(error){
  if(current()){state.inboxOpening=null;render();}
  if(state.view==='inbox'&&sameWorkspace(state,token))toast(error.message);
 }
}
export function bindConversation(root,state,render,reload){
 clearTimeout(refreshTimer);
 const container=root.querySelector('[data-inline-conversation]'),conversation=state.inboxConversation;if(!container||!conversation)return;
 const token=workspaceToken(state),record=conversation.record,scope=`company=${token.company}`;
 const current=()=>state.view==='inbox'&&sameWorkspace(state,token)&&state.inboxConversation===conversation;
 const form=container.querySelector('[data-inline-reply]');
 form.elements.body.oninput=()=>{
  conversation.draft=form.elements.body.value;conversation.requestId=nonce();conversation.error='';
  form.querySelector('.form-error').textContent='';
 };
 form.elements.body.onkeydown=event=>{
  if(event.key!=='Enter'||event.shiftKey||event.isComposing||event.keyCode===229)return;
  event.preventDefault();
  if(!event.repeat&&form.elements.body.value.trim())form.requestSubmit();
 };
 bindReplyTemplates(container,state,conversation,form.elements.body);
 const refreshLocal=async()=>{if(!current()||state.view!=='inbox')return;try{const next=await api(`/inbox/threads/${record.id}?${scope}`);if(!current())return;const changed=JSON.stringify(next)!==JSON.stringify(conversation.record);conversation.record=next;if(changed){await reload(state);if(!current())return;window.dispatchEvent(new Event('omnigo-inbox-read'));render();return;}if(next.jobs.some(job=>activeReplyState(job.state)))refreshTimer=setTimeout(refreshLocal,4000);}catch(error){if(current()){conversation.error=error.message;render();}}};
 if(record.jobs.some(job=>activeReplyState(job.state))&&!conversation.watchedReply)refreshTimer=setTimeout(refreshLocal,4000);
 container.querySelector('[data-refresh-conversation]').onclick=async event=>{event.currentTarget.disabled=true;try{await api(`/inbox/threads/${record.id}/refresh?${scope}`,'POST',{});await refreshLocal();}catch(error){if(current()){conversation.error=error.message;render();}}};
 container.querySelector('[data-close-conversation]').onclick=()=>{
  if(conversation.draft?.trim()||conversation.files?.length||conversation.sending){
   toast(i18nT('Önce yanıtınızı gönderin veya taslağı temizleyin.'));return;
  }
  state.inboxConversation=null;render();
  root.querySelector('[data-inbox-thread="'+record.id+'"]')?.focus({preventScroll:true});
 };
 container.querySelectorAll('[data-remove-attachment]').forEach(button=>button.onclick=()=>{conversation.files=conversation.files.filter(file=>file.id!==button.dataset.removeAttachment);conversation.requestId=nonce();render();});
 if(form.elements.attachments)form.elements.attachments.onchange=async()=>{
  const chosen=[...form.elements.attachments.files];if(chosen.length+conversation.files.length>3){form.querySelector('.form-error').textContent='En fazla 3 dosya ekleyebilirsiniz.';return;}
  form.querySelector('[type=submit]').disabled=true;
  try{for(const file of chosen){if(file.size>2097152)throw new Error(i18nT('Her dosya en fazla 2 MB olabilir.'));const data=new FormData();data.append('file',file);const saved=await upload(`/inbox/threads/${record.id}/attachments?${scope}`,data);if(!current())return;conversation.files.push(saved);}conversation.requestId=nonce();conversation.error='';}
  catch(error){conversation.error=error.message;}finally{if(current())render();}
 };
 form.onsubmit=async event=>{
  event.preventDefault();if(conversation.sending||!current()||form.elements.body.disabled)return;
  const body=form.elements.body.value.trim();if(!body)return;
  if(hasReplyPlaceholder(body)){
   conversation.error=i18nT('Göndermeden önce şablondaki il veya ilçe bilgisini doldurun.');
   form.querySelector('.form-error').textContent=conversation.error;
   return;
  }
  conversation.sending=true;form.querySelector('[type=submit]').disabled=true;
  try{
   if(!await confirmAction(i18nT('Mesajı göndermek istiyor musunuz?'),{focusApprove:true})||!current())return;
   const result=await enqueueInboxReply(scope,record.id,body,record.data.fingerprint,conversation.requestId,conversation.files.map(file=>file.id));
   if(!sameWorkspace(state,token))return;
   const jobId=result.jobId;
   if(!jobId)throw new Error(i18nT('Gönderim onayı alınamadı. Sonucu konuşmadan kontrol edin.'));
   watchReplyDelivery(state,record.id,jobId,{
    awaitsMarketplace:record.provider==='Hepsiburada',
    onProgress(next){
     if(current()){conversation.record=next;render();}
    },
    async onUpdate(next){
     if(current()){conversation.record=next;conversation.watchedReply=null;render();}
     if(state.view!=='inbox'||!sameWorkspace(state,token))return;
     await reload(state);
     if(state.view!=='inbox'||!sameWorkspace(state,token))return;
     document.querySelector('[data-marketplace-questions]')?.inboxPaint?.();
     window.dispatchEvent(new Event('omnigo-inbox-read'));
    },
    onTimeout(){
     if(current()){conversation.watchedReply=null;render();}
    },
   });
   if(!current())return;
   conversation.draft='';conversation.files=[];conversation.error='';conversation.watchedReply=jobId;
   conversation.record={...conversation.record,replyBody:body,jobs:[{id:jobId,kind:'reply',state:'pending'},...conversation.record.jobs.filter(job=>job.id!==jobId)]};
   void reload(state).then(()=>{if(current())render();}).catch(()=>{});
   toast(i18nT('Yanıt gönderim sırasına alındı.'));
  }catch(error){if(current())conversation.error=error.message;}finally{
   conversation.sending=false;
   if(current()){form.querySelector('[type=submit]').disabled=false;render();}
  }
 };
 const reviewRejection=container.querySelector('[data-review-inbox-rejection]');
 if(reviewRejection)reviewRejection.onclick=async()=>{
  if(!current()||conversation.draft?.trim()||conversation.files?.length||conversation.sending){
   toast(i18nT('Önce yanıtınızı gönderin veya taslağı temizleyin.'));return;
  }
  reviewRejection.disabled=true;
  try{
   await api(`/inbox/threads/${record.id}/review-rejection?${scope}`,'POST',{}, {confirmed:true});
   if(!current())return;
   state.inboxConversation=null;
   await reload(state);
   if(state.view==='inbox'&&sameWorkspace(state,token))render();
  }catch(error){if(current()){reviewRejection.disabled=false;toast(error.message);}}
 };
 container.querySelectorAll('[data-resolve-inbox-job]').forEach(review=>review.onsubmit=async event=>{
  event.preventDefault();if(!current())return;const button=review.querySelector('button');button.disabled=true;
  try{await api(`/inbox/jobs/${review.dataset.resolveInboxJob}/resolve?${scope}`,'POST',{resolution:review.elements.resolution.value,checkedMarketplace:review.elements.checked.checked});if(!current())return;conversation.record=await api(`/inbox/threads/${record.id}?${scope}`);if(current()){await reload(state);window.dispatchEvent(new Event('omnigo-inbox-read'));render();}}catch(error){review.querySelector('.form-error').textContent=error.message;button.disabled=false;}
 });
}
