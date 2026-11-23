import {html,t,currentLocale} from './i18n.js';
import {esc,icon,platform} from './ui.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {inboxStatuses} from './inbox-status.js';
import {loadingState} from './content-state.js';
import {salesProductImage} from './top-products.js';
import {questionReplyForm} from './inbox-row-reply.js';
import {tableCopyButton} from './table-copy-button.js';

const providers={
 hepsiburada:'Hepsiburada',koctas:'Koçtaş',pazarama:'Pazarama',n11:'n11',idefix:'idefix',trendyol:'Trendyol',
};
const formatDate=value=>new Date(value).toLocaleString(currentLocale(),{
 timeZone:'Europe/Istanbul',day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',
});

const dateText=(value,dateOnly=false)=>dateOnly?new Date(value).toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul'}):formatDate(value);
const validDate=value=>Boolean(value&&Number.isFinite(Date.parse(value)));
export function inboxMessageCopyButton(message){
 return inboxCopyButton(message,'inbox-message-copy',t('Mesajı kopyala'));
}

function inboxCopyButton(value,field,label){
 return value?tableCopyButton({label,data:{[field]:JSON.stringify(value)},placement:'inbox-message-copy'}):'';
}

function copyIdentifier(label,value){
 return value?html`<div class="inbox-code"><span>${t(label)} <strong>${esc(value)}</strong></span></div>`:'';
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
 return html`<table class="inbox-question-table corporate-table" aria-label="Pazaryeri soruları">
  <colgroup><col class="inbox-col-product"><col class="inbox-col-message"><col class="inbox-col-action"></colgroup>
  <thead><tr><th scope="col">Ürün bilgileri</th><th scope="col">Mesaj bilgileri</th><th scope="col">Yanıtlar</th></tr></thead>
  <tbody>${rows.map(row=>{
   const message=row.canInspect===false?null:row.question??row.preview;
   const title=row.canInspect===false?t(row.title):questionProductTitle(row.title);
   const productName=row.canInspect!==false&&row.title!=='Ürün adı bekleniyor'?row.title:null;
   const detail=row.canInspect===false?'':html`<button class="btn" type="button" data-inbox-thread="${row.threadId}" data-inbox-read="${row.id}" aria-controls="inbox-question-detail" ${selected===row.threadId?'aria-current="true"':''}>İncele</button>`;
   const deadline=validDate(row.expiresAt)?html`<div class="inbox-reply-deadline"><span>Son yanıt tarihi</span><time datetime="${esc(row.expiresAt)}">${esc(formatDate(row.expiresAt))}</time></div>`:'';
   return html`<tr class="${row.unread?'unread':''}" ${selected===row.threadId?'data-selected="true"':''}>
   <td><div class="inbox-product">
    ${questionProductPhoto(row)}
    <div class="inbox-product-text">
     <strong>${esc(title)}${inboxCopyButton(productName,'inbox-product-copy',t('Kopyala')+' · '+t('Ürün adı'))}</strong>
     <div class="inbox-product-platform">${platform(row.provider)}</div>
     ${copyIdentifier('SKU',row.sku)}${copyIdentifier('Ürün kodu',row.productCode)}${copyIdentifier('Sipariş no',row.orderNumber)}
    </div>
   </div></td>
   <td class="inbox-message-cell"><p class="inbox-question-message">${esc(message??t('Soru metni bu kayıtta saklanmıyor.'))}${inboxMessageCopyButton(message)}</p>
    ${validDate(row.created)?html`<time class="inbox-question-date" datetime="${esc(row.created)}">${esc(dateText(row.created,row.dateOnly))}</time>`:''}
   </td>
   <td class="inbox-reply-cell">${questionReplyForm(row,state,detail,deadline)}</td>
  </tr>`;}).join('')}</tbody>
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
