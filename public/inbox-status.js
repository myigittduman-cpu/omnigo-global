import {labelMap as localizedLabels} from './i18n.js';
import {html as i18nHtml} from './i18n.js';
export const inboxStatuses=localizedLabels({waiting:'Yanıt bekleyen',answered:'Yanıtlanan',approval_pending:'Onay bekleyen',rejected:'Reddedildi'});
export const selectedInboxStatus=state=>Object.hasOwn(inboxStatuses,state.inboxStatus)?state.inboxStatus:'waiting';
export function conversationStatus(record){
 if(record.status)return record.status;
 const data=record.data;
 if(data.questionStatus===0&&data.localReplyState==='sent')return 'answered';
 if(data.questionStatus!==undefined)return ['waiting','answered','approval_pending','rejected'][data.questionStatus]||'waiting';
 return data.replyNeeded?'waiting':'answered';
}
export function inboxStatusFilters(state){
 const count=state.inboxData?.waitingCount;
 const option=([value,label])=>i18nHtml`<button type="button" data-inbox="status" data-inbox-status="${value}" aria-pressed="${selectedInboxStatus(state)===value}">${label}${value==='waiting'&&Number.isSafeInteger(count)?` (${count})`:''}</button>`;
 const options=Object.entries(inboxStatuses);
 return i18nHtml`<div class="inbox-status-filters" role="group" aria-label="Mesaj durumu">${options.map(option).join('')}</div>`;
}
