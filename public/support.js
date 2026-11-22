import {notificationDetailShell} from './notification-detail.js';
import {customerCan} from './customer-access.js';
import {labelMap as localizedLabels} from './i18n.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {esc,openDialog,toast,empty} from './ui.js';
import {loadingState} from './content-state.js';

const labels=localizedLabels({open:'Yanıt bekliyor',answered:'Yanıtlandı',closed:'Çözüldü'});
const stamp=value=>new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
const requestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(24)),v=>v.toString(16).padStart(2,'0')).join('');
const statusBadge=value=>i18nHtml`<span class="support-status ${esc(value)}">${esc(labels[value]||value)}</span>`;
const liveSupport=()=>i18nHtml`<aside class="support-live"><div><strong>Canlı destek için</strong><span>WhatsApp üzerinden Omnigo ekibine ulaşın.</span></div><a class="btn support-whatsapp" href="https://wa.me/905475456696" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp canlı destek (yeni pencerede açılır)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.7a8.5 8.5 0 0 1-12.6 7.5L3 20.5l1.3-4.8a8.5 8.5 0 1 1 16.2-4Z"/><path d="m8 7 2 3-1.2 1.2a8 8 0 0 0 4 4L14 14l3 2c-1 3-4 2-7-1S6 8 8 7Z"/></svg><span>WhatsApp</span></a></aside>`;

export async function loadSupport(state){
 if(state.view!=='support'||!state.user||!customerCan(state,'support'))return;
 const company=state.company,token=workspaceToken(state),page=state.supportPage||0;
 try{
  const data=await api(`/support/tickets?company=${company}&page=${page}`);
  if(!sameWorkspace(state,token)||(state.supportPage||0)!==page)return;
  state.supportData=data;state.supportError='';
 }catch(error){if(!sameWorkspace(state,token))return;if(error.status===401)throw error;state.supportError=error.message;}
}

export function supportContent(state){return i18nHtml`<section data-support-content>${supportBody(state)}</section>`;}
export function updateSupportPage(root,state){const target=root.querySelector('[data-support-content]');if(target&&!target.contains(document.activeElement))target.innerHTML=supportBody(state);}
function supportBody(state){
 const admin=state.user.role==='admin',data=state.supportData;
 const actions=i18nHtml`<div class="support-toolbar">${!admin&&customerCan(state,'support','create')?i18nHtml('<button type="button" class="btn primary" data-support="new">Yeni talep oluştur</button>'):''}</div>`;
 const cards=data?.items?.map(item=>i18nHtml`<article class="panel support-card" data-support-status="${esc(item.status)}"><div class="support-card-meta"><span>${item.category==='question'?'Soru':i18nT('Destek talebi')} · #${item.id}</span>${statusBadge(item.status)}</div><button type="button" class="support-open" data-support-ticket="${item.id}"><strong>${esc(item.subject)}</strong><span>Konuşmayı aç →</span></button><time>Son işlem: ${esc(stamp(item.updated))}</time></article>`).join('');
 return i18nHtml`<p class="support-intro">${admin?i18nT('Seçili firmanın sorularını yanıtlayın ve destek taleplerini takip edin.'):i18nT('Omnigo ekibine soru sorun veya destek talebi açın. Yanıtlar aynı konuşmada paylaşılır.')}</p>${admin?'':liveSupport()}${actions}${state.supportError?i18nHtml`<p class="form-error" role="alert">${esc(state.supportError)}</p>`:''}<div class="support-grid">${!data&&!state.supportError?loadingState('Destek talepleri yükleniyor…'):cards||empty(i18nT('Henüz destek talebi yok'),admin?i18nT('Müşteriniz talep açtığında burada görünecek.'):i18nT('Yeni talep oluşturarak ekibimizle iletişime geçebilirsiniz.'))}</div>${data?.items?.length?i18nHtml`<div class="support-toolbar"><button type="button" class="btn" data-support="prev" ${!state.supportPage?'disabled':''}>Önceki</button><span class="pagination-current" aria-current="page">Sayfa ${(state.supportPage||0)+1}</span><button type="button" class="btn" data-support="next" ${!data.hasMore?'disabled':''}>Sonraki</button></div>`:''}`;
}

function createTicket(state,render){
 const company=state.company,token=workspaceToken(state),nonce=requestId();
 const dialog=openDialog(i18nT('Yeni destek talebi'),i18nHtml`<form class="form-grid support-create"><label class="full-width">Konu<input name="subject" required maxlength="150" placeholder="Size hangi konuda yardımcı olabiliriz?"></label><label class="full-width">Talep türü<select name="category"><option value="question">Soru sormak istiyorum</option><option value="issue">Destek istiyorum</option></select></label><label class="full-width">Mesajınız<textarea name="body" rows="6" required maxlength="3000" placeholder="Konuyu ve ihtiyacınızı yazın."></textarea></label><p class="form-help full-width">Mesajınız firmanız ve Omnigo ekibi tarafından görülebilir.</p><p class="form-error full-width" role="alert"></p><button type="submit" class="btn primary">Talebi gönder</button></form>`);
 const form=dialog.querySelector('form');
 form.onsubmit=async event=>{
  event.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;
  try{
   const result=await api(`/support/tickets?company=${company}`,'POST',{...Object.fromEntries(new FormData(form)),requestId:nonce});
   if(!sameWorkspace(state,token))return;
   dialog.close();state.supportPage=0;await loadSupport(state);render();toast(i18nT('Talebiniz Omnigo ekibine iletildi.'));await openTicket(result.id,state,render);
  }catch(error){form.querySelector('.form-error').textContent=error.message;}finally{button.disabled=false;}
 };
}

export async function openTicket(id,state,render,before=0,{host=null,onLoaded}={}){
 const company=state.company,token=workspaceToken(state),admin=state.user.role==='admin';
 const shell=notificationDetailShell(state,token,i18nT('Destek konuşması'),'support-detail-dialog',{host}),dialog=shell.dialog;
 let item;try{item=await api(`/support/tickets/${id}?company=${company}&before=${before}`,'GET',undefined,{signal:shell.signal});}catch(error){shell.fail(error);return dialog;}
 if(!shell.active())return dialog;
 const closed=item.status==='closed',nonce=requestId();
 const messages=item.messages.map(message=>i18nHtml`<article class="support-message ${message.sender_role==='admin'?'support-message-team':''}"><header><strong>${message.sender_role==='admin'?'Omnigo ekibi':i18nT('Müşteri')}</strong><time>${esc(stamp(message.created))}</time></header><p>${esc(message.body)}</p></article>`).join('');
 shell.title(item.subject);shell.show(i18nHtml`<div class="support-toolbar">${statusBadge(item.status)}<button type="button" class="text-btn" data-support-status="${closed?'open':'closed'}">${closed?i18nT('Talebi yeniden aç'):admin?i18nT('Çözüldü olarak işaretle'):'Talebi kapat'}</button><button type="button" class="text-btn" data-support-reload>Konuşmayı yenile</button></div><div class="support-reply-layout"><div class="support-conversation">${item.hasOlder?i18nHtml('<button type="button" class="text-btn" data-support-older>Önceki mesajlar</button>'):''}${messages}${before?i18nHtml('<button type="button" class="text-btn" data-support-latest>Son mesajlara dön</button>'):''}</div><form class="form-grid support-reply"><label>Mesajınız<textarea name="body" rows="6" required maxlength="3000" ${closed?'disabled':''}></textarea></label><p class="form-help">${closed?(admin?i18nT('Yanıt yazmak için talebi yeniden açın.'):i18nT('Talebiniz çözüldü. Yeni bir konu için destek talebi oluşturabilirsiniz.')):admin?i18nT('Yanıtınız müşterinin portalındaki bu konuşmada görünecek.'):i18nT('Mesajınız Omnigo’ya iletilecek.')}</p><p class="form-error" role="alert"></p><button type="submit" class="btn primary" ${closed?'disabled':''}>${admin?i18nT('Müşteriye yanıt gönder'):i18nT('Mesajı gönder')}</button></form></div>`);
 const form=dialog.querySelector('form');
 if(!customerCan(state,'support','reply'))form.hidden=true;
 const statusButton=dialog.querySelector('[data-support-status]');
 if(!admin)statusButton.remove();
 if(admin){
  const remove=document.createElement('button');remove.type='button';remove.className='text-btn';remove.dataset.supportDelete='';remove.textContent=i18nT('Talebi sil');
  dialog.querySelector('.support-toolbar').append(remove);
  remove.onclick=async()=>{
   if(!safe())return;remove.disabled=true;
   try{
    await api(`/support/tickets/${id}?company=${company}`,'DELETE');
    if(!sameWorkspace(state,token))return;
    dialog.close();state.supportPage=0;await loadSupport(state);if(!sameWorkspace(state,token))return;
    render();toast(i18nT('Destek talebi silindi.'));
   }catch(error){if(!error.cancelled)form.querySelector('.form-error').textContent=error.message;}
   finally{remove.disabled=false;}
  };
 }
 const safe=()=>{if(!shell.active())return false;if(form.elements.body.value.trim()){toast(i18nT('Önce yazdığınız mesajı gönderin veya metni temizleyin.'));return false;}return true;};
 async function reload(older=0){if(!safe())return;try{await openTicket(id,state,render,older,{host:dialog});}catch(error){toast(error.message);}}
 dialog.querySelector('[data-support-reload]').onclick=()=>reload();
 dialog.querySelector('[data-support-latest]')?.addEventListener('click',()=>reload());
 dialog.querySelector('[data-support-older]')?.addEventListener('click',()=>reload(item.messages[0].id));
 if(!shell.active())return dialog;
 if(admin)statusButton.onclick=async event=>{
  if(!safe())return;const button=event.currentTarget;button.disabled=true;
  try{await api(`/support/tickets/${id}/status?company=${company}`,'POST',{status:button.dataset.supportStatus});if(!shell.active())return;await loadSupport(state);if(!shell.active())return;render();await openTicket(id,state,render,0,{host:dialog});}
  catch(error){form.querySelector('.form-error').textContent=error.message;button.disabled=false;}
 };
 form.onsubmit=async event=>{
  event.preventDefault();const button=form.querySelector('[type=submit]');button.disabled=true;
  try{await api(`/support/tickets/${id}/reply?company=${company}`,'POST',{body:form.elements.body.value,requestId:nonce});if(!shell.active())return;form.reset();await loadSupport(state);if(!shell.active())return;render();await openTicket(id,state,render,0,{host:dialog});toast(i18nT('Mesajınız iletildi.'));}
  catch(error){form.querySelector('.form-error').textContent=error.message;button.disabled=false;}
 };
 if(shell.active()){
  if(admin)void api(`/notifications/support/${id}/read?company=${company}`,'POST',{}).catch(()=>{if(shell.active())toast('Bildirim okundu olarak kaydedilemedi.');});
  if(onLoaded)void Promise.resolve(onLoaded()).catch(()=>{if(shell.active())toast('Bildirim okundu olarak kaydedilemedi.');});
 }
 return dialog;
}

export async function supportClick(button,state,render){
 try{
  if(button.dataset.supportTicket){await openTicket(Number(button.dataset.supportTicket),state,render);return;}
  const action=button.dataset.support;if(action==='new'){createTicket(state,render);return;}
  if(action==='prev')state.supportPage=Math.max(0,(state.supportPage||0)-1);
  if(action==='next'&&state.supportData?.hasMore)state.supportPage=(state.supportPage||0)+1;
  await loadSupport(state);render();
 }catch(error){toast(error.message);}
}

