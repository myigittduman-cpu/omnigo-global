import {currentLocale,html,text} from './i18n.js';
import {esc} from './ui.js';
import {stockCodeGroups,stockCodeQuantity} from './stock-code-groups.js';

const present=value=>String(value??'').trim()!=='';

function groupProducts(items){
 return stockCodeGroups(items).map(group=>{
  const quantity=stockCodeQuantity(group.items);
  const names=[...new Set(group.items.map(row=>row.productName).filter(present))];
  return {...group,id:group.items[0].id,quantity,names};
 }).sort((a,b)=>(b.quantity??0)-(a.quantity??0));
}

export function shipmentSkuLines(items,{codeView=code=>html`<strong class="shipment-sku-code">${esc(code)}</strong>`,extraDetails=()=>'',menuKey=id=>Number(id),codeAction=()=>''}={}){
 const lines=groupProducts(items).map(group=>{
  const code=group.code?codeView(group.code):html`<span class="shipment-grouped-secondary">Kod aktarılmadı</span>`;
  const quantity=group.quantity===null?'—':group.quantity.toLocaleString(currentLocale());
  const names=[...group.names].map(name=>html`<strong>${esc(name)}</strong>`).join('');
  const detailLabel=names?'Ürünün tam adını göster':'Ürün kodunu göster';
  const detail=names||group.code?html`<details class="shipment-product-name" data-shipment-product-name="${esc(String(menuKey(group.id)))}">
   <summary aria-label="${detailLabel}" title="${detailLabel}">
    <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg>
   </summary>
   <div class="shipment-operations-panel shipment-product-name-panel">${names?html`<small>Ürünün tam adı</small>${names}`:''}${group.code?html`<small>Ürün kodu</small><strong>${esc(group.code)}</strong>`:''}${extraDetails(group.items)}</div>
  </details>`:'';
  return html`<div class="shipment-product-code-line"><div class="shipment-product-code" title="${esc(group.code)}">${code}</div><span class="shipment-product-quantity">${group.quantity===null?quantity:esc(text`${quantity} adet`)}</span>${detail}${group.code?codeAction(group.code):''}</div>`;
 }).join('');
 return lines?html`<div class="shipment-sku-lines">${lines}</div>`:html`<span class="shipment-grouped-secondary">Ürün bilgisi aktarılmamış</span>`;
}
