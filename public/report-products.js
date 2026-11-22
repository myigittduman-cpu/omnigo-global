import {rawTurnoverMoney,turnoverMoney} from './sales-turnover.js';
import {rankBestsellers} from './report-bestsellers.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,money} from './ui.js';

export const productsPerPage=5;

export function productPageData(products,requestedPage=1){
 const total=products.length,totalPages=Math.max(1,Math.ceil(total/productsPerPage));
 const value=Number(requestedPage);
 const page=Math.min(totalPages,Math.max(1,Number.isFinite(value)?Math.trunc(value):1));
 const offset=(page-1)*productsPerPage;
 return {page,totalPages,total,from:total?offset+1:0,to:Math.min(offset+productsPerPage,total),items:products.slice(offset,offset+productsPerPage)};
}

function pageButtons(page,totalPages){
 const start=Math.max(1,Math.min(page-2,totalPages-4));
 const pages=[...new Set([1,...Array.from({length:Math.min(5,totalPages)},(_,index)=>start+index),totalPages])].sort((a,b)=>a-b);
 return pages.map((number,index)=>i18nHtml`${index&&number-pages[index-1]>1?i18nHtml('<span class="report-page-gap" aria-hidden="true">…</span>'):''}<button type="button" class="btn" data-product-page="${number}" aria-label="${number}. sayfa" ${number===page?'aria-current="page"':''}>${number}</button>`).join('');
}

export function reportProducts(products,{amounts=true,gross=false,neutral=false,adjusted=false,afterCancellation=false}={}){
 const items=rankBestsellers(products);
 const heading=i18nHtml`<div class="report-section-title"><h2>En çok satanlar</h2><span>${i18nT(gross?'Satış adedine göre ilk 10 ürün':'Net satış adedine göre ilk 10 ürün')}</span></div>`;
 if(!items.length)return heading+i18nHtml('<p class="report-empty">Seçili aralıkta satış adedi doğrulanmış ürün bulunmuyor.</p>');
 return i18nHtml`${heading}<div class="report-table-scroll"><table><thead><tr><th>Sıra</th><th>Ürün / stok kodu</th><th>Pazaryerleri</th><th>${i18nT(gross?'Satış adedi':'Net satış adedi')}</th>${amounts?i18nHtml`<th>${i18nT(neutral?'Ciro':adjusted?'İptal sonrası ciro':'Satış tutarı')}</th>`:''}${afterCancellation?i18nHtml`<th>İptaller Sonrası Ciro</th>`:''}</tr></thead><tbody>${items.map(product=>i18nHtml`<tr><td>${product.rank}</td><td><strong>${esc(product.title)}</strong><small>${esc(product.sku||'—')}</small></td><td>${esc(product.channels.join(', '))}</td><td>${product.netQuantity}</td>${amounts?i18nHtml`<td>${gross?rawTurnoverMoney(product):turnoverMoney(product)}</td>`:''}${afterCancellation?i18nHtml`<td>${product.salesTurnoverCents===null?i18nT('Bekleniyor'):money(product.salesTurnoverCents)}</td>`:''}</tr>`).join('')}</tbody></table></div>`;
}
