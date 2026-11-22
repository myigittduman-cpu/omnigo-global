import {html,t,currentLocale} from './i18n.js';
import {esc,icon,platform} from './ui.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {inboxStatuses} from './inbox-status.js';
import {loadingState} from './content-state.js';
import {shipmentCopyButton} from './shipment-copy.js';
import {salesProductImage} from './top-products.js';
import {activeReplyState,replyJobDetail,sourceAnsweredLabel} from './inbox-reply-delivery.js';

const providers={
 hepsiburada:'Hepsiburada',koctas:'Koçtaş',pazarama:'Pazarama',n11:'n11',idefix:'idefix',trendyol:'Trendyol',
};
const formatDate=value=>new Date(value).toLocaleString(currentLocale(),{
 timeZone:'Europe/Istanbul',day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',
});

const dateText=(value,dateOnly=false)=>dateOnly?new Date(value).toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul'}):formatDate(value);
const validDate=value=>Boolean(value&&Number.isFinite(Date.parse(value)));
export const rowCanReply=row=>row.replyState!=='source_answered'&&(row.canReply??(row.status==='waiting'&&['Koçtaş','Pazarama'].includes(row.provider)));

const replyStatusLabels={
 pending:'Gönderim sırada',running:'Gönderiliyor',verifying:'Pazaryeri onayı bekleniyor',sent:'Pazaryerine iletildi',
 accepted:'Pazaryerinde değerlendirmeye alındı',failed:'Gönderilemedi',unknown:'Gönderim doğrulanamadı',confirmed_sent:'Onaylandı',source_answered:sourceAnsweredLabel,cancelled:'İletilmediği doğrulandı',
};
function questionReplyStatus(row){
 if(!Object.hasOwn(replyStatusLabels,row.replyState))return '';
 const state=row.replyState,label=replyStatusLabels[state];
 const pending=activeReplyState(state);
 const confirmed=state==='confirmed_sent';
 const problem=['failed','unknown','cancelled'].includes(state);
 const detail=replyJobDetail(state,row.replyError);
 const badge=pending?'pending':confirmed?'confirmed':problem?'problem':'';
 const marker=pending?'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0':confirmed?'M5 12l4 4L19 6':problem?'M12 8v5m0 4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0':'';
 return html`<div class="inbox-row-reply-result" data-reply-state="${esc(row.replyState)}" ${detail?html`title="${esc(t(detail))}"`:''}>
  <strong class="inbox-reply-badge ${badge?`inbox-reply-badge--${badge}`:''}">${marker?html`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${marker}"/></svg>`:''}${esc(t(label))}</strong>
 </div>`;
}

function copyIdentifier(label,value){
 return value?html`<div class="inbox-code"><span>${t(label)} <strong>${esc(value)}</strong></span>${shipmentCopyButton('questionIdentifier',t(label),{inline:true,value})}</div>`:'';
}

function questionProductPhoto(record){
 const url=questionPhotoPath(record.imageUrl);
 return html`<span class="inbox-product-image" aria-hidden="true">${icon('inventory')}${url?html`<img src="${esc(url)}" width="54" height="54" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">`:''}</span>`;
}

function questionPhotoPath(value){
 if(typeof value!=='string')return '';
 if(/^\/assets\/inbox-products\/[a-z0-9][a-z0-9-]{2,100}\.webp$/.test(value))return value;
 const local=salesProductImage(value);
 if(local)return local;
 try{
  const url=new URL(value);
  if(url.origin==='https://img.pzrmcdn.com'&&!url.username&&!url.password)return url.href;
 }catch{}
 return '';
}

function questionProductTitle(title){
 return title==='Ürün adı bekleniyor'||!title?t('Ürün adı bekleniyor'):title;
}

export function questionProviders(state){
 const channels=state.data?.channels||[];
 const names=new Set([...channels.map(channel=>channel.name),
  ...(state.inboxData?.items||[]).map(row=>row.provider)]);
 return Object.entries(providers).filter(([key,name])=>
  marketplaceVisible(name)&&(names.has(name)||key===state.inboxProvider));
}

export function selectedQuestionProvider(state){
 const options=questionProviders(state);
 return options.some(([key])=>key===state.inboxProvider)?state.inboxProvider:options[0]?.[0]||'';
}

export function inboxQuestionProviderButtons(state){
 const options=questionProviders(state);
 if(!options.length)return '';
 const selected=selectedQuestionProvider(state);
 const counts=state.inboxData?.providerCounts??{};
 return html`<div class="inbox-provider-filters" role="group" aria-label="Pazaryeri seçimi">${options.map(([value,label])=>{
  const count=counts[value];
  return html`<button type="button" data-inbox="provider" data-inbox-provider-filter="${value}" aria-pressed="${selected===value}">${esc(label)}${Number.isSafeInteger(count)&&count>0?html`<span class="inbox-provider-count">${count.toLocaleString(currentLocale())}</span>`:''}</button>`;
 }).join('')}</div>`;
}

export const selectedQuestionSort=state=>state.inboxSort==='oldest'?'oldest':'newest';

export function inboxQuestionFilters(state){
 const sort=selectedQuestionSort(state);
 return html`<form class="inbox-filters" data-inbox-filters aria-label="Soru filtreleri">
  <label><span id="inbox-search-label">SKU veya sipariş numarası</span><input type="search" name="q" aria-labelledby="inbox-search-label" value="${esc(state.inboxSearch||'')}" maxlength="100" autocomplete="off"></label>
  <label><span id="inbox-provider-label">Pazaryeri</span><select name="provider" aria-labelledby="inbox-provider-label">
   ${questionProviders(state).map(([key,name])=>html`<option value="${key}" ${selectedQuestionProvider(state)===key?'selected':''}>${esc(name)}</option>`).join('')}
  </select></label>
  <label><span>Sıralama</span><select name="sort">
   <option value="newest" ${sort==='newest'?'selected':''}>Yeniden eskiye</option>
   <option value="oldest" ${sort==='oldest'?'selected':''}>Eskiden yeniye</option>
  </select></label>
  <button class="btn" type="submit">Uygula</button>
 </form>`;
}

export function inboxQuestionList(data,state){
 if(!data)return state.inboxError?'':loadingState('Sorular yükleniyor…',{compact:true});
 if(!data.available)return html`<p class="inbox-empty" role="status">Soru listesi şu anda kullanılamıyor.</p>`;
 const selected=state.inboxOpening||state.inboxConversation?.record.id;
 const rows=data.items.filter(row=>marketplaceVisible(row.provider));
 if(!rows.length)return html`<p class="inbox-empty" role="status">Bu filtrede soru bulunmuyor.</p>`;
 return html`<table class="inbox-question-table corporate-table" data-copyable-table aria-label="Pazaryeri soruları">
  <colgroup><col class="inbox-col-product"><col class="inbox-col-message"><col class="inbox-col-action"></colgroup>
  <thead><tr><th scope="col">Ürün bilgileri</th><th scope="col">Mesaj bilgileri</th><th scope="col">İşlemler</th></tr></thead>
  <tbody>${rows.map(row=>html`<tr class="${row.unread?'unread':''}" ${selected===row.threadId?'data-selected="true"':''}>
   <td><div class="inbox-product">
    ${questionProductPhoto(row)}
    <div class="inbox-product-text">
     <strong>${esc(row.canInspect===false?t(row.title):questionProductTitle(row.title))}</strong>
     <div class="inbox-product-platform">${platform(row.provider)}</div>
     ${copyIdentifier('SKU',row.sku)}${copyIdentifier('Ürün kodu',row.productCode)}${copyIdentifier('Sipariş no',row.orderNumber)}
    </div>
   </div></td>
   <td><p class="inbox-question-message">${esc(row.canInspect===false?t('Soru metni bu kayıtta saklanmıyor.'):row.question??row.preview)}</p>
    ${validDate(row.created)?html`<time class="inbox-question-date" datetime="${esc(row.created)}">${esc(dateText(row.created,row.dateOnly))}</time>`:''}
   </td>
   <td><div class="inbox-row-actions"><span class="inbox-row-status">${esc(inboxStatuses[row.status]||inboxStatuses.waiting)}</span>
    <div class="inbox-row-feedback">${questionReplyStatus(row)}</div>
    <div class="inbox-row-action-line">
     <div class="inbox-reply-deadline">${validDate(row.expiresAt)?html`<span>Son yanıt tarihi</span><time datetime="${esc(row.expiresAt)}">${esc(formatDate(row.expiresAt))}</time>`:''}</div>
     ${row.canInspect===false?'':html`<button class="btn" type="button" data-inbox-thread="${row.threadId}" data-inbox-read="${row.id}" aria-controls="inbox-question-detail" ${selected===row.threadId?'aria-current="true"':''}>${t(rowCanReply(row)?'Yanıtla':'İncele')}</button>`}
    </div>
   </div></td>
  </tr>`).join('')}</tbody>
 </table>`;
}

export function inboxQuestionPages(data,page=0){
 if(!data||(!page&&!data.hasMore))return '';
 const pages=Number.isSafeInteger(data.total)?Math.max(1,Math.ceil(data.total/10)):page+1+Number(data.hasMore);
 const start=Math.max(0,Math.min(page-2,pages-5));
 const visible=Array.from({length:Math.min(pages,5)},(_,index)=>start+index);
 const button=index=>html`<button type="button" class="btn" data-inbox="page" data-inbox-page="${index}" ${page===index?'aria-current="page"':''}>${index+1}</button>`;
 return html`<nav class="inbox-pagination" aria-label="Soru sayfaları">
 <button type="button" class="btn" data-inbox="page" data-inbox-page="${page-1}" ${!page?'disabled':''}>Önceki</button>
 ${visible.map(button).join('')}
 <button type="button" class="btn" data-inbox="page" data-inbox-page="${page+1}" ${!data.hasMore?'disabled':''}>Sonraki</button>
 </nav>`;
}

export function inboxQuestionMetadata(record){
 const data=record.data||{};
 const first=record.messages.find(message=>message.sender==='CUSTOMER_USER');
 const fields=data.questionIdentifiers||{};
 const order=fields.orderNumber||data.entities?.find(entity=>entity.type==='MMP_ORDER')?.id;
 return html`<header class="inbox-question-meta">
  <span>${esc(record.provider)} · ${esc(inboxStatuses[record.status]||t('Soru ayrıntısı'))}</span>
  <div class="inbox-product">${questionProductPhoto(data)}<h2 tabindex="-1" data-question-heading>${esc(questionProductTitle(data.title))}</h2></div>
  <dl>${[
   ['SKU',fields.sku],['Ürün kodu',fields.productCode],['Sipariş numarası',order],
   ['Soru tarihi',validDate(first?.created)?dateText(first.created,first.dateOnly):null],
   ['Son yanıt tarihi',record.status==='waiting'&&validDate(data.expiresAt)?formatDate(data.expiresAt):null],
  ].filter(([, value])=>value).map(([label,value])=>html`<div><dt>${t(label)}</dt><dd>${esc(value)}</dd></div>`).join('')}</dl>
 </header>`;
}
