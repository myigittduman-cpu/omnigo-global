import {turnoverValue,turnoverMoney,turnoverTotal,turnoverDays,turnoverNote} from './sales-turnover.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,money} from './ui.js';
import {curatedProductImage} from './product-media.js';

export function productImage(product) {
 let url=salesProductImage(product.imageUrl)||curatedProductImage(product)?.imageUrl||'';
 if(!url)try {const value=new URL(product.imageUrl);if(value.protocol==='https:'&&!value.username&&!value.password&&!value.port)url=value.href;}catch{}
 return i18nHtml`<span class="product-image"><span class="product-image-empty">Görsel bekleniyor</span>${url?i18nHtml`<img src="${esc(url)}" alt="${esc(product.title)}" loading="lazy" decoding="async" referrerpolicy="no-referrer">`:''}</span>`;
}
export function salesProductImage(value){
 if(typeof value!=='string'||!value.startsWith('/api/sales/product-image?'))return '';
 try{const url=new URL(value,'https://portal.invalid');return /^\d+$/.test(url.searchParams.get('company')||'')&&/^[a-f0-9]{64}$/.test(url.searchParams.get('productKey')||'')&&/^[a-f0-9]{48}$/.test(url.searchParams.get('v')||'')?url.pathname+url.search:'';}catch{return '';}
}
export function topProductsView(products,amountLabel=i18nT('satış tutarı')) {
 if(!products.length)return i18nHtml('<div class="sales-blank">Bu tarih aralığında TOP 10 için doğrulanmış ürün satışı bulunmuyor.</div>');
 return i18nHtml`<section class="top-products" aria-live="off" aria-label="En çok satılan ürünler slaytı" aria-roledescription="slayt gösterisi"><header><div><span class="eyebrow">TOP 10</span><h3>En çok satılan ürünler</h3><p>Seçili pazaryeri ve tarih aralığı · Satış adedine göre</p></div><div class="top-product-controls"><button type="button" data-slide-prev aria-label="Önceki ürün">‹</button><button type="button" data-slide-play>Duraklat</button><button type="button" data-slide-next aria-label="Sonraki ürün">›</button></div></header><div class="top-product-slides">${products.map((p,i)=>i18nHtml`<article class="top-product-slide" ${i?'hidden':''} aria-label="${i+1} / ${products.length}" aria-roledescription="slayt">${productImage(p)}<div><span class="top-product-rank">${String(i+1).padStart(2,'0')} / ${String(products.length).padStart(2,'0')}</span><h4>${esc(p.title)}</h4><p>${esc(p.sku||i18nT('Ürün kodu paylaşılmadı'))}</p><strong>${esc(p.netQuantity)} adet</strong><span>${turnoverMoney(p)} ${esc(amountLabel)}</span></div></article>`).join('')}</div><div class="top-product-dots">${products.map((p,i)=>i18nHtml`<button type="button" data-slide-index="${i}" aria-label="${i+1}. ürünü göster" aria-pressed="${i===0}"></button>`).join('')}</div></section>`;
}
export function bindProductImages(root) {
 root.querySelectorAll('.product-image img').forEach(image=>{
  const show=()=>image.parentElement.classList.add('has-image');
  image.addEventListener('load',show,{once:true});image.addEventListener('error',()=>image.remove(),{once:true});
  if(image.complete&&image.naturalWidth)show();
 });
}
export function bindTopProducts(root) {
 const section=root.querySelector('.top-products');if(!section)return ()=>{};
 const slides=[...section.querySelectorAll('.top-product-slide')],dots=[...section.querySelectorAll('[data-slide-index]')];
 const play=section.querySelector('[data-slide-play]'),motion=window.matchMedia('(prefers-reduced-motion: reduce)');
 let current=0,playing=!motion.matches&&slides.length>1,hover=false,focused=false;
 const update=()=>{play.textContent=playing?i18nT('Duraklat'):'Oynat';play.disabled=slides.length<2;section.querySelectorAll('[data-slide-prev],[data-slide-next]').forEach(b=>b.disabled=slides.length<2);};
 const show=index=>{current=(index+slides.length)%slides.length;slides.forEach((s,i)=>s.hidden=i!==current);dots.forEach((d,i)=>d.setAttribute('aria-pressed',String(i===current)));};
 section.querySelector('[data-slide-prev]').onclick=()=>{show(current-1);playing=false;update();};
 section.querySelector('[data-slide-next]').onclick=()=>{show(current+1);playing=false;update();};
 dots.forEach(dot=>dot.onclick=()=>{show(Number(dot.dataset.slideIndex));playing=false;update();});
 play.onclick=()=>{playing=!playing;if(playing){focused=false;hover=false;}update();};
 section.onmouseenter=()=>hover=true;section.onmouseleave=()=>hover=false;
 section.onfocusin=()=>focused=true;section.onfocusout=event=>focused=section.contains(event.relatedTarget);
 const reduce=()=>{if(motion.matches){playing=false;update();}};motion.addEventListener('change',reduce);
 const timer=setInterval(()=>{if(!section.isConnected){cleanup();return;}if(playing&&!hover&&!focused&&!document.hidden)show(current+1);},5000);
 const cleanup=()=>{clearInterval(timer);motion.removeEventListener('change',reduce);};update();return cleanup;
}
