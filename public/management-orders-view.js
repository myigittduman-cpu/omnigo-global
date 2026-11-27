import {html,t,text,currentLocale} from './i18n.js';
import {esc,money,platform} from './ui.js';
import {orderDate} from './delivery-view.js';
import {shipmentSkuLines} from './shipment-sku-lines.js';
import {shipmentNamedProducts} from './shipment-product-policy.js';
import {shipmentStatuses,shipmentStatusLegend} from './shipment-status.js';
import {paginationButtons} from './pagination-controls.js';
import {turkeyCities} from './turkey-cities.js';
import {tableCopyButton,tableCopyLine} from './table-copy-button.js';

const channels={hepsiburada:'Hepsiburada',n11:'n11',idefix:'idefix',pttavm:'PTT AVM',pazarama:'Pazarama',koctas:'Koçtaş',trendyol:'Trendyol'};
const columns=['Sipariş no / Tarih ve saat','Müşteri','E-posta / Telefon','SKU/Adet','Teslimat adresi','Fatura türü','Fatura tutarı'];
const value=text=>esc(String(text||'—'));

function orderCopyButton(key,label,raw){
 return String(raw??'').trim()?tableCopyButton({
  label:text`${t(label)} bilgisini kopyala`,
  data:{'shipment-copy':key,'shipment-copy-value':raw},
 }):'';
}

function copyField(key,label,raw,content=value(raw)){
 return tableCopyLine(content,orderCopyButton(key,label,raw));
}

export function managementOrdersShell(model){
 return html`<form class="orders-filters" data-order-filters role="search">
  <label class="orders-search">Sipariş ara<input type="search" maxlength="120" name="query" value="${esc(model.query)}" placeholder="Sipariş no, müşteri, telefon veya SKU"></label>
  <label>Pazaryeri<select name="provider"><option value="all">Tüm pazaryerleri</option>${Object.entries(channels).map(([id,label])=>html`<option value="${id}" ${id===model.provider?'selected':''}>${esc(label)}</option>`).join('')}</select></label>
  <label>Teslimat ili<select name="province"><option value="all">Tüm iller</option>${[...turkeyCities].sort((a,b)=>a.localeCompare(b,'tr')).map(city=>html`<option ${city===model.province?'selected':''}>${esc(city)}</option>`).join('')}</select></label>
  <button type="submit" class="btn">Filtrele</button>
 </form>
 <div class="table-status-bar">${shipmentStatusLegend(true)}
  <p class="orders-feedback" role="status" data-management-orders-status></p>
 </div>
 <div class="orders-table-wrap management-orders-scroll corporate-table-viewport" tabindex="0" aria-label="Sipariş tablosu">
  <table class="management-orders-table corporate-table" data-copyable-table><thead><tr>${columns.map(header=>html`<th scope="col">${esc(t(header==='SKU/Adet'&&model.productMode!=='skuQuantity'?'Ürün':header))}</th>`).join('')}</tr></thead><tbody></tbody></table>
 </div><nav class="orders-footer" data-orders-pagination aria-label="Sipariş sayfaları"></nav>`;
}
function skuCell(order,productMode){
 if(productMode==='fullName')return html`<div class="shipment-product-full-names">${shipmentNamedProducts(order.products||[]).map(product=>html`<div class="shipment-named-product">${copyField('productName','Ürün adı',product.name||t('Ürün bilgisi aktarılmamış'))}<span class="shipment-named-quantity">${product.quantity===null?t('Adet aktarılmamış'):esc(text`${product.quantity.toLocaleString(currentLocale())} adet`)}</span></div>`).join('')||t('Ürün bilgisi aktarılmamış')}</div>`;
 return shipmentSkuLines((order.products||[]).map((product,index)=>({...product,id:index,productName:product.title})),{
  menuKey:index=>String(order.id)+'-'+index,
  codeAction:code=>orderCopyButton('productCode','SKU',code)
 });
}
export function managementOrderRows(items,productMode='skuQuantity'){
 return items.map(order=>{
  const destinations=order.destinations?.length?order.destinations:[{}];
  const number=(order.orderNumbers||[]).join(', ')||order.externalId;
  const tone=shipmentStatuses.find(status=>status.value===order.displayRowStatus);
  const span=destinations.length;
  const amount=Number.isSafeInteger(order.invoiceCents)?money(order.invoiceCents):null;
  const orderLink=html`<button type="button" class="text-btn order-number" data-order-detail="${Number(order.id)}">${value(number)}</button>`;
  return destinations.map((place,index)=>{
   const location=[place.district,place.province].filter(Boolean).join(' / ');
   const address=[place.deliveryAddress,location].filter(part=>String(part??'').trim()).join('\n');
   const addressView=value(place.deliveryAddress)+(location?html`<small>${esc(location)}</small>`:'');
   const recipient=place.recipientName||(span===1?order.customerNames?.join(', '):'');
   return html`<tr class="management-order-row ${tone?'shipment-row-'+tone.value:''}" data-order-id="${Number(order.id)}">
    ${index?'':html`<td rowspan="${span}">${copyField('orderNumber','Sipariş numarası',number,orderLink)}
     <small>${esc(order.createdAt?orderDate(order.createdAt):'—')}</small>${platform(order.provider)}
     ${tone?html`<small>${esc(t(tone.label))}</small>`:''}</td>`}
    <td>${copyField('recipientName','Müşteri adı',recipient)}</td><td>${value(place.email||place.phone)}${place.email&&place.phone?html`<small>${esc(place.phone)}</small>`:''}</td>
    ${index?'':html`<td class="shipment-sku-cell" rowspan="${span}">${skuCell(order,productMode)}</td>`}
    <td>${copyField('deliveryAddress','Teslimat adresi',address,addressView)}</td><td>${copyField('billingType','Fatura türü',place.invoiceType)}</td>
    ${index?'':html`<td class="management-order-amount" rowspan="${span}">${copyField('invoiceCents','Fatura tutarı',amount)}</td>`}
   </tr>`;
  }).join('');
 }).join('');
}
export function managementOrdersPager(data){
 const count=Math.ceil(data.total/data.pageSize);
 if(count<2)return '';
 const page=data.page;
 return html`<button type="button" class="btn" data-orders-page="${page-1}" ${page===0?'disabled':''}>Önceki</button>
 ${paginationButtons({page,count,attribute:'data-orders-page'})}
 <button type="button" class="btn" data-orders-page="${page+1}" ${page+1>=count?'disabled':''}>Sonraki</button>`;
}
