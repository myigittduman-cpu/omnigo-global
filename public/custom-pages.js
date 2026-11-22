import {applicationPageMarkup} from './application-page-builder.js';
import {esc} from './ui.js';
import {customPageItems,customPageForView,isCustomPageView} from './custom-page-navigation.js';
export function customPagesContent(state){
 const pages=customPageItems(state);
 if(!pages.length)return '<section class="empty-state"><h2>Yayımlanmış özel sayfa yok</h2><p>Yetkili yönetici Uygulama Yönetimi içinden yeni sayfa yayımlayabilir.</p></section>';
 const active=customPageForView(state)||pages.find(page=>page.slug===state.customPageSlug)||pages[0];state.customPageSlug=active.slug;
 if(isCustomPageView(state.view))return `<section class="custom-pages" data-custom-pages>${applicationPageMarkup(active)}</section>`;
 return `<section class="custom-pages" data-custom-pages><nav aria-label="Özel sayfalar">${pages.map(page=>`<button type="button" data-custom-page="${esc(page.slug)}" aria-pressed="${page.slug===active.slug}">${esc(page.title)}</button>`).join('')}</nav><header><h2>${esc(active.title)}</h2>${active.summary?`<p>${esc(active.summary)}</p>`:''}</header>${applicationPageMarkup(active)}</section>`;
}
export function bindCustomPages(app,state,render){
 app.querySelector('[data-custom-pages]')?.addEventListener('click',event=>{const button=event.target.closest('[data-custom-page]');if(!button)return;state.customPageSlug=button.dataset.customPage;render();});
}
