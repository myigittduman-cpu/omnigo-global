import {contentError,loadingState} from './content-state.js';
import {currentLocale,html,t,text} from './i18n.js';
import {paginationButtons} from './pagination-controls.js';
import {esc} from './ui.js';
import {cityProductCities,workspaceCities} from './turkey-cities.js';
import {orderDate} from './delivery-view.js';
import {customerCan} from './customer-access.js';
import {documentOutputActions} from './document-output-actions.js';
import {shipmentProductMode} from './shipment-product-policy.js';
import {cityProductSortField} from './city-products-filters.js';

const headers=['Sipariş Tarihi','Teslim Alacak Kişi','Tel No','Adres','İl','İlçe','Ürün SKU','Adet'];

function productLine(row,showQuantity,productMode){
 if(productMode==='fullName')return html`<div class="city-product-full-name" data-product-id="${esc(row.id)}">${esc(row.productName||t('Ürün bilgisi aktarılmamış'))}</div>`;
 const nameMenu=row.productName||row.sku?html`
  <details class="shipment-product-name" data-shipment-product-name="${esc(row.id)}">
   <summary aria-label="${esc(text`${row.sku||t('Ürün')} · Ürün adını göster`)}" title="Ürün adını göster">
    <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg>
   </summary>
   <div class="shipment-operations-panel shipment-product-name-panel"><small>Ürün kodu</small><strong>${esc(row.sku||'—')}</strong>${row.productName?html`<small>Ürün adı</small><strong>${esc(row.productName)}</strong>`:''}</div>
  </details>`:'';
 return html`<div class="city-products-sku" data-product-id="${esc(row.id)}"><strong title="${esc(row.sku||'—')}">${esc(row.sku||'—')}</strong>
  ${showQuantity?html`<small class="city-product-count">${esc(text`${row.quantity} adet`)}</small>`:''}${nameMenu}
 </div>`;
}

function destinationFields(delivery,showQuantities,productMode){
 const contacts=['recipientName','phone','address','city','district'].map(key=>esc(delivery[key]||'—'));
 const products=delivery.products.map(product=>productLine(product,showQuantities,productMode)).join('');
 return [...contacts,products];
}

function orderCells(order,productMode){
 // An open tab may still receive the previous, line-based response during a rollout.
 const deliveries=order.deliveries??[{...order,products:[order]}];
 if(deliveries.length===1){
  const delivery=deliveries[0],fields=destinationFields(delivery,delivery.products.length>1,productMode);
  return fields.map((field,index)=>index===5?html`<td class="shipment-sku-cell">${field}</td>`:html`<td>${field}</td>`).join('');
 }
 // Keep separate destinations and their products aligned within the same order row.
 return html`<td colspan="6" class="city-products-destinations">${deliveries.map(delivery=>html`
   <div class="city-product-destination" role="group" aria-label="${esc(delivery.recipientName||t('Teslimat'))}">
    ${destinationFields(delivery,true,productMode).map((field,index)=>html`<div role="group" class="${index===5?'shipment-sku-cell':''}" aria-label="${esc(t(index===5&&productMode==='fullName'?'Ürün':headers[index+1]))}">${field}</div>`).join('')}
   </div>`).join('')}</td>`;
}

export function cityProductRows(data,productMode='skuQuantity'){
 return data.items.map(order=>html`<tr${order.cancellationMark?' class="shipment-row-cancelled"':''} data-product-line="${esc(order.id)}">
  <td><time datetime="${esc(order.orderedAt||'')}">${esc(order.orderedAt?orderDate(order.orderedAt):'—')}</time>${order.cancellationMark?html`<small class="city-products-cancellation">${t('Kısmi iptal')}</small>`:''}</td>
  ${orderCells(order,productMode)}<td class="city-products-quantity">${esc(order.quantity??'—')}</td>
 </tr>`).join('');
}

export function cityProductsPager(data){
 if(!data||!data.total)return '';
 const page=data.page,count=Math.ceil(data.total/data.pageSize);
 const total=data.total.toLocaleString(currentLocale());
 const countLabel=data.items?.[0]?.deliveries?text`${total} sipariş`:text`${total} kayıt`;
 return html`<span class="city-products-count">${countLabel}</span>
  <div class="city-products-page-buttons">
   <button type="button" class="btn" data-products-page="${page-1}" ${page===0?'disabled':''}>Önceki</button>
   ${paginationButtons({page,count,attribute:'data-products-page'})}
   <button type="button" class="btn" data-products-page="${page+1}" ${page+1>=count?'disabled':''}>Sonraki</button>
  </div>`;
}

export function cityProductsMessage(data,error){
 if(error)return contentError(error)+html`<button type="button" class="btn" data-products-retry>Yeniden dene</button>`;
 if(!data)return loadingState('Siparişler yükleniyor…',{compact:true});
 if(!data.cities.length)return html`<p>Bu mağazanın teslimat kapsamında listelenecek il bulunmuyor.</p>`;
 if(data.items.length)return '';
 return data.city==='all'?html`<p>Teslimat kapsamındaki iller için sipariş bulunmuyor.</p>`:html`<p>${esc(data.city)} için sipariş bulunmuyor.</p>`;
}

export function cityProductChooser(cities,selected='all'){
 if(!cities.length)return '';
 return html`<div class="city-products-city-select">
  <label class="city-products-filter">Teslimat ili
   <select data-products-city-picker aria-controls="city-products-panel">
    <option value="all">Tüm İller</option>
    ${[...cities].sort((a,b)=>a.localeCompare(b,'tr')).map(city=>html`<option value="${esc(city)}" ${city===selected?'selected':''}>${esc(city)}</option>`).join('')}
   </select>
  </label></div>`;
}

export function cityProductsContent(state){
 const cities=cityProductCities(workspaceCities(state));
 const productMode=shipmentProductMode(state.company);
 const canExport=customerCan(state,'cityProducts','export');
 return html`<section class="city-products" data-city-products>
  <div class="city-products-toolbar"><div data-products-chooser>${cityProductChooser(cities)}</div>
   ${cityProductSortField()}
   <div class="city-products-outputs" data-products-outputs ${canExport?'':'hidden'}>
    ${documentOutputActions({excel:{attr:'data-products-output',value:'excel',disabled:true},pdf:{attr:'data-products-output',value:'pdf',disabled:true},
       print:{attr:'data-products-output',value:'print',disabled:true},share:{attr:'data-products-output',value:'whatsapp',disabled:true}})}
   </div></div>
  <p class="city-products-output-status" data-products-output-status role="status" aria-live="polite" ${canExport?'':'hidden'}></p>
  <section role="region" id="city-products-panel" aria-label="Şehir Dışı Ürünler">
   <div class="city-products-scroll corporate-table-viewport" tabindex="0" aria-label="Şehir dışı ürün tablosu">
    <table class="city-products-table corporate-table" data-copyable-table>
     <thead><tr>${headers.map(label=>html`<th scope="col">${esc(t(label==='Ürün SKU'&&productMode==='fullName'?'Ürün':label))}</th>`).join('')}</tr></thead><tbody></tbody>
    </table>
   </div>
   <div class="city-products-message" data-products-status>${cityProductsMessage(null,'')}</div>
   <nav class="city-products-pager" data-products-pages aria-label="Ürün tablosu sayfaları"></nav>
  </section>
 </section>`;
}
