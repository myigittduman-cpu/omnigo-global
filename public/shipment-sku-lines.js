import {currentLocale,html,text} from './i18n.js';
import {esc} from './ui.js';

const present=value=>String(value??'').trim()!=='';

function groupProducts(items){
 const groups=new Map();
 for(const row of items){
  const code=present(row.productCode)?String(row.productCode):'';
  const key=code&&row.invoiceProductIdentity?JSON.stringify([code,row.invoiceProductIdentity]):Symbol();
  if(!groups.has(key))groups.set(key,{id:row.id,code,items:[],names:new Set(),quantity:0});
  const group=groups.get(key);
  group.items.push(row);
  if(present(row.productName))group.names.add(row.productName);
  const quantity=Number(row.quantity),total=group.quantity+quantity;
  group.quantity=group.quantity!==null&&quantity>0&&Number.isSafeInteger(quantity)&&Number.isSafeInteger(total)?total:null;
 }
 return [...groups.values()].sort((a,b)=>(b.quantity??0)-(a.quantity??0));
}

export function shipmentSkuLines(items,{codeView=code=>html`<strong class="shipment-sku-code">${esc(code)}</strong>`,extraDetails=()=>'',menuKey=id=>Number(id)}={}){
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
  return html`<div class="shipment-product-code-line"><div class="shipment-product-code" title="${esc(group.code)}">${code}</div><span class="shipment-product-quantity">${group.quantity===null?quantity:esc(text`${quantity} adet`)}</span>${detail}</div>`;
 }).join('');
 return lines?html`<div class="shipment-sku-lines">${lines}</div>`:html`<span class="shipment-grouped-secondary">Ürün bilgisi aktarılmamış</span>`;
}
