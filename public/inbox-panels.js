import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc,icon,platform} from './ui.js';
import {marketplaceVisible} from './marketplace-visibility.js';

// Counts describe this page only: the server paginates across all marketplaces.
export function marketplacePanels(rows,channels=[],expanded=new Set(),conversation=null){
 const sources=['Hepsiburada','Koçtaş','Pazarama','n11','idefix','Trendyol'];
 const names=[...new Set(rows.map(r=>r.provider))].filter(marketplaceVisible);
 const unavailable=channels.filter(channel=>channel.status==='active'&&marketplaceVisible(channel.name)&&!sources.includes(channel.name)&&!names.includes(channel.name)).map(channel=>channel.name);
 return i18nHtml`${unavailable.length?i18nHtml`<p class="inbox-help">${esc(i18nText`Soru aktarımı henüz etkin değil · ${unavailable.join(', ')}`)}</p>`:''}${names.length?'':i18nHtml`<p class="inbox-empty" role="status">Bu filtrede soru bulunmuyor.</p>`}<div class="inbox-marketplaces">${names.map((name,index)=>{
  const items=rows.filter(r=>r.provider===name);
  const unread=items.filter(r=>r.unread).length;
  const cards=items.map(r=>i18nHtml`<article class="inbox-item ${r.unread?'unread':''}"><div class="inbox-item-top"><span class="inbox-company">${esc(r.company)}</span><time>${esc(new Date(r.created).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}))}</time></div><button type="button" class="inbox-open" data-inbox-thread="${r.threadId}" data-inbox-read="${r.id}"><strong>${esc(r.title)}</strong><span>${esc(r.preview)}</span><small>${r.unread?i18nT('Okunmamış · '):''}${r.replyNeeded?i18nT('Yanıt bekliyor'):i18nT('Konuşmayı incele')} ${icon('arrow')}</small></button>${conversation?.id===r.threadId?conversation.html:''}</article>`);
  const isExpanded=expanded.has(name),remaining=Math.max(0,cards.length-2),listId=`inbox-more-${index}`;
  const more=remaining?i18nHtml`<div id="${listId}" class="inbox-more-items" ${isExpanded?'':'hidden'}>${cards.slice(2).join('')}</div><footer class="inbox-panel-footer"><button type="button" class="btn inbox-expand" data-inbox="toggle" data-inbox-provider="${esc(name)}" data-inbox-remaining="${remaining}" aria-expanded="${isExpanded}" aria-controls="${listId}">${isExpanded?i18nT('Daha az göster'):i18nText`Tamamını gör (${remaining} daha)`}</button></footer>`:'';
  return i18nHtml`<section class="inbox-marketplace" aria-label="${esc(name)} mesajları"><header class="inbox-marketplace-head">${platform(name)}<span class="inbox-panel-count">${items.length} kayıt${unread?i18nText` · ${unread} okunmamış`:''}</span></header><div class="inbox-list">${cards.slice(0,2).join('')}${more}</div></section>`;
 }).join('')}</div>`;
}
