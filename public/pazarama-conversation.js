import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

export async function pazaramaConversation(record,readId,state,render,reload){
 const token=workspaceToken(state),stamp=v=>new Date(v).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'});
 const statuses={0:i18nT('Yanıt bekliyor'),1:i18nT('Yanıtlandı'),2:i18nT('Yanıt onay bekliyor'),3:i18nT('Yanıt reddedildi')};
 const messages=record.messages.map(m=>i18nHtml`<article class="inbox-message ${m.sender==='SHOP_USER'?'outgoing':''}"><strong>${m.sender==='SHOP_USER'?i18nT('Mağaza'):i18nT('Müşteri')}</strong><time>${esc(stamp(m.created))}</time><p>${esc(m.body)}</p></article>`).join('');
 const dialog=openDialog(record.data.title,i18nHtml`<p class="inbox-help">Pazarama · ${esc(statuses[record.data.questionStatus]||i18nT('Ürün sorusu'))}</p><div class="inbox-conversation">${messages}</div><p class="form-help">Sorular ve mevcut cevaplar otomatik aktarılır. Yeni yanıtınızı Pazarama panelinden gönderebilirsiniz.</p><a class="btn" href="https://isortagim.pazarama.com/" target="_blank" rel="noopener noreferrer">Pazarama panelini aç</a>`);
 dialog.addEventListener('close',async()=>{if(!sameWorkspace(state,token))return;await reload(state);if(sameWorkspace(state,token)&&!document.querySelector('dialog[open]'))render();},{once:true});
 if(readId)try{await api(`/inbox/notifications/${readId}/read?company=${token.company}`,'POST',{});}catch(error){toast(error.message);}
}
