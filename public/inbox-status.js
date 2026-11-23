import {labelMap as localizedLabels} from './i18n.js';
import {html as i18nHtml} from './i18n.js';
export const inboxStatuses=localizedLabels({answered:'Yanıtlanan',approval_pending:'Onay bekleyen',rejected:'Reddedildi',waiting:'Yanıt bekleyen'});
export const selectedInboxStatus=state=>Object.hasOwn(inboxStatuses,state.inboxStatus)?state.inboxStatus:'waiting';
export function conversationStatus(record){
 if(record.status)return record.status;
 const data=record.data;
 if(data.questionStatus===0&&data.localReplyState==='sent')return 'answered';
 if(data.questionStatus!==undefined)return ['waiting','answered','approval_pending','rejected'][data.questionStatus]||'waiting';
 return data.replyNeeded?'waiting':'answered';
}
export function inboxStatusFilters(state){
 const count=state.inboxData?.rejectedCount||0;
 const option=value=>i18nHtml`<button type="button" data-inbox="status" data-inbox-status="${value}" aria-pressed="${selectedInboxStatus(state)===value}" ${value==='rejected'&&count>0?'data-inbox-rejection-alert':''}>${inboxStatuses[value]}${value==='rejected'&&count>0?` (${count})`:''}</button>`;
 const options=['answered','approval_pending','rejected'];
 return i18nHtml`<div class="inbox-status-filters" role="group" aria-label="Mesaj durumu">${options.map(option).join('')}</div>`;
}
