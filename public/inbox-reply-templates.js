import {html} from './i18n.js';
import {esc} from './ui.js';
import {vizyonaReplyTemplates} from './inbox-reply-catalog.js';

const normalize=value=>String(value??'').trim().toLocaleLowerCase('tr-TR');

export function replyTemplates(state){
 const company=state.companies?.find(item=>Number(item.id)===Number(state.company));
 const isVizyona=['vizyona','vızyona'].includes(normalize(company?.name));
 return state.user?.role==='admin'&&isVizyona?vizyonaReplyTemplates:[];
}

export function replyTemplateMarkup(items,open=false){
 if(!items.length)return '';
 return html`<aside class="inbox-reply-templates" data-reply-templates>
  <button class="inbox-template-rail" type="button" data-reply-templates-toggle aria-controls="inbox-reply-template-panel" aria-expanded="${open}">
   <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><span>Hazır cevaplar</span>
  </button>
  <div class="inbox-template-picker" id="inbox-reply-template-panel" data-reply-template-panel ${open?'':'hidden'}>
   <input type="search" data-reply-template-search placeholder="Cevaplarda ara" aria-label="Hazır cevaplarda ara" autocomplete="off">
   <div class="inbox-template-options">${items.map(({body},index)=>html`<button class="btn" type="button" data-use-reply-template="${index}">${esc(body)}</button>`).join('')}</div>
   <p data-template-empty hidden>Aramaya uygun şablon bulunamadı.</p>
  </div>
 </aside>`;
}

export const hasReplyPlaceholder=body=>/\[(?:İl\/)?İlçe\]/iu.test(body);

export function bindReplyTemplates(container,state,conversation,composer){
 const picker=container.querySelector('[data-reply-templates]');
 if(!picker)return;
 const toggle=picker.querySelector('[data-reply-templates-toggle]');
 const panel=picker.querySelector('[data-reply-template-panel]');
 const search=panel.querySelector('[data-reply-template-search]');
 const buttons=[...panel.querySelectorAll('[data-use-reply-template]')];
 const empty=panel.querySelector('[data-template-empty]');
 const setOpen=open=>{
  conversation.templatesOpen=open;
  toggle.setAttribute('aria-expanded',String(open));
  panel.hidden=!open;
 };
 toggle.onclick=()=>{
  setOpen(panel.hidden);
  if(!panel.hidden)search.focus({preventScroll:true});
 };
 search.oninput=()=>{
  const query=normalize(search.value);
  for(const button of buttons)button.hidden=!normalize(button.textContent).includes(query);
  empty.hidden=buttons.some(button=>!button.hidden);
 };
 for(const button of buttons)button.onclick=()=>{
  const item=replyTemplates(state)[Number(button.dataset.useReplyTemplate)];
  if(!item||composer.disabled||conversation.sending||conversation.watchedReply)return;
  composer.value=item.body;
  composer.dispatchEvent(new Event('input'));
  setOpen(false);
  search.value='';search.dispatchEvent(new Event('input'));
  composer.focus({preventScroll:true});
  const placeholder=item.body.match(/\[(?:İl\/)?İlçe\]/u);
  if(placeholder)composer.setSelectionRange(placeholder.index,placeholder.index+placeholder[0].length);
  else composer.setSelectionRange(item.body.length,item.body.length);
 };
}
