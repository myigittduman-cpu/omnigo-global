import {api} from './api.js';
import {showFeedback} from './save-feedback.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {t} from './i18n.js';

export const activeReplyState=state=>['pending','running','verifying'].includes(state);
export const sourceAnsweredLabel='Pazaryerinde yanıtlandı';
export const replyJobDetail=(state,error)=>state==='source_answered'?t('Bu denemeden yeni yanıt gönderilmedi.'):error;
export const replyRequestId=()=>[...crypto.getRandomValues(new Uint8Array(24))].map(value=>value.toString(16).padStart(2,'0')).join('');

export function enqueueInboxReply(scope,threadId,body,fingerprint,requestId,attachments=[],signal){
 return api(`/inbox/threads/${threadId}/reply?${scope}`,'POST',{body,fingerprint,requestId,attachments},{confirmed:true,signal});
}

export function replyDeliveryFeedback(state) {
 if(state==='source_answered')return {kind:'info',message:'Pazaryerinde yanıtlandı. Bu denemeden yeni yanıt gönderilmedi.'};
 if(state==='confirmed_sent')return {kind:'success',message:'Yanıtınız pazaryerinde onaylandı.'};
 if(state==='sent')return {kind:'success',message:'Yanıtınız gönderildi.'};
 if(state==='accepted')return {kind:'info',message:'Trendyol yanıtınızı değerlendirmeye aldı.'};
 if(state==='failed'||state==='cancelled')return {kind:'info',message:'Yanıt gönderilemedi. Ayrıntıyı konuşmada görebilirsiniz.'};
 if(state==='unknown')return {kind:'info',message:'Gönderim doğrulanamadı. Pazaryerindeki konuşmayı kontrol edin.'};
 return null;
}

export function watchReplyDelivery(state,threadId,jobId,{onUpdate,onProgress,onTimeout,awaitsMarketplace=false,notify=true,trackActiveStates=false}) {
 const token=workspaceToken(state);
 const deadline=Date.now()+300_000;
 const path='/inbox/threads/'+threadId+'?company='+token.company;
 let lastState;
 const poll=async()=>{
  if(!sameWorkspace(state,token))return;
  if(Date.now()>=deadline){
   onTimeout();
   if(notify)showFeedback(awaitsMarketplace?'Pazaryeri onayı bekleniyor. Sonucu konuşmadan izleyebilirsiniz.':'Gönderim henüz doğrulanmadı. Sonucu konuşmadan izleyebilirsiniz.');
   return;
  }
  let record;
  try {
   record=await api(path,'GET',undefined,{signal:AbortSignal.timeout(12_000)});
  } catch(error) {
   if(error.status!==401&&error.status!==403&&error.status!==404)setTimeout(poll,6000);
   else onTimeout();
   return;
  }
  if(!sameWorkspace(state,token))return;
  const job=record.jobs?.find(item=>item.id===jobId&&item.kind==='reply');
  if(job?.state!==lastState&&(job?.state==='verifying'||trackActiveStates&&activeReplyState(job?.state)))onProgress?.(record);
  lastState=job?.state;
  const feedback=replyDeliveryFeedback(job?.state);
  if(feedback) {
   try { await onUpdate(record); } catch { /* The next regular refresh can update the list. */ }
   if(notify&&sameWorkspace(state,token))showFeedback(feedback.message,{kind:feedback.kind,force:true});
   return;
  }
  setTimeout(poll,4000);
 };
 setTimeout(poll,1500);
}
