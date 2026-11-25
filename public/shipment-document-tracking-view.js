import {contentError,loadingState} from './content-state.js';
import {currentLocale,html,t,text} from './i18n.js';
import {paginationButtons} from './pagination-controls.js';
import {esc,money,platform} from './ui.js';
import {shipmentSkuLines} from './shipment-sku-lines.js';

import {tableCopyButton,tableCopyLine} from './table-copy-button.js';

function trackingCopy(key,label,value){
 return String(value??'').trim()?tableCopyButton({
  label:text`${t(label)} bilgisini kopyala`,data:{'shipment-copy':key,'shipment-copy-value':value},
 }):'';
}

const formatDate=value=>{
 const date=new Date(value||'');
 return Number.isNaN(date.getTime())?'—':date.toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul'});
};

export function shipmentTrackingSearch(view){
 const id=view+'-search';
 return html`<form class="shipment-search shipment-tracking-search" role="search" data-tracking-search autocomplete="off">
  <label for="${id}">Kayıtlarda ara</label>
  <div class="shipment-search-controls">
   <input id="${id}" type="search" name="query" maxlength="120" autocomplete="off" placeholder="Sipariş no, ürün adı veya müşteri adı">
   <button type="submit" class="btn primary">Ara</button>
   <button type="button" class="btn" data-tracking-clear disabled>Temizle</button>
  </div>
 </form>`;
}

function trackingFooter({data,pageSize}){
 const page=data?.page||0,total=data?.total||0,pages=Math.max(1,Math.ceil(total/pageSize));
 return html`<nav class="shipment-tracking-pager" aria-label="Belge tablosu sayfaları">
  <label>Sayfa başına sipariş<select data-tracking-size><option value="10" ${pageSize===10?'selected':''}>10</option><option value="50" ${pageSize===50?'selected':''}>50</option></select></label>
  <span class="shipment-tracking-count" aria-live="polite">${text`${total} kayıt`}</span>
  <div class="shipment-tracking-page-controls">
   <button type="button" class="btn" data-tracking-page="${page-1}" ${page===0?'disabled':''}>Önceki</button>
   ${paginationButtons({page,count:pages,attribute:'data-tracking-page'})}
   <button type="button" class="btn" data-tracking-page="${page+1}" ${page+1>=pages?'disabled':''}>Sonraki</button>
  </div>
 </nav>`;
}

export function shipmentTrackingResult(page,model){
 const {data,error,canUpload,query,canManage}=model,invoice=page.kind==='invoice';
 const blank=error?html`${contentError(error)}<button type="button" class="btn" data-tracking-retry>Tekrar dene</button>`:
  !data?loadingState('Belgeler yükleniyor…',{compact:true}):
   html`<p class="report-empty" role="status">${t(query?'Aramayla eşleşen kayıt bulunamadı.':'Fatura kaydı bulunamadı.')}</p>`;
 const rows=data?.items.map(row=>{
  const completed=row.documents?.[page.kind]===true;
  const customer=row.customerNameSource==='recipient'?'':row.customerName;
  return html`<tr${row.cancellationMark?' class="shipment-row-cancelled"':''} data-shipment-row="${row.id}">
  ${canManage?html`<td class="shipment-tracking-check"><input type="checkbox" data-tracking-select="${row.id}" aria-label="${esc(text`Sipariş ${row.orderNumber||row.id} seç`)}"></td>`:''}
  <td class="shipment-tracking-order">${tableCopyLine(html`<strong>${esc(row.orderNumber||'—')}</strong>`,trackingCopy('orderNumber','Sipariş numarası',row.orderNumber))}<small>${formatDate(row.orderedAt)}</small></td>
  <td>${platform(row.provider)}</td>
  <td>
   ${tableCopyLine(esc(customer||'—'),trackingCopy('customerName','Müşteri adı',customer))}
   <small class="shipment-tracking-name"><span>${t('Alıcı adı')}:</span> ${esc(row.recipientName||'—')}</small>
  </td>
  <td class="shipment-sku-cell">${shipmentSkuLines([row],{codeAction:(code,quantity)=>trackingCopy('productCode','SKU ve adet',quantity===null?code:text`${code} · ${quantity} adet`)})}</td><td class="shipment-tracking-city">${esc(row.city)}</td>
  ${invoice?html`<td class="shipment-tracking-amount">${Number.isSafeInteger(row.invoiceCents)?money(row.invoiceCents):'—'}</td>`:''}
  <td>${row.marketplaceReturn?.returnedQuantity>0?t(row.marketplaceReturn.partial?'Kısmi iade':'İade onaylandı'):row.cancellationMark==='partial'?t('Kısmi iptal'):row.cancellationMark==='cancelled'||row.displayRowStatus==='cancelled'?t('İptal edildi'):row.delivered?html`<span class="shipment-tracking-delivered" data-tracking-delivered ${row.deliveredManually?'title="'+esc(t('Manuel olarak işaretlendi'))+'"':''}>Teslim Edildi</span>`:'—'}</td>
  <td>${row.documentRestricted?'—':t(completed?'Tamamlandı':'Bekliyor')}${completed&&row.documentUpdated?html`<small>${formatDate(row.documentUpdated)}</small>`:''}</td>
  <td>${!row.documentRestricted&&(completed?model.canDocuments!==false:canUpload)?html`<button type="button" class="btn" data-shipment-document="${page.kind}">${t(completed?'Belgeyi aç':page.upload)}</button>`:'—'}</td>
 </tr>`;
 }).join('');
 return html`<div class="shipment-tracking-scroll corporate-table-viewport" tabindex="0" aria-label="${esc(t(page.name))}">
  <table class="corporate-table shipment-tracking-table" data-copyable-table data-document-kind="${page.kind}">
   <thead><tr>
    ${canManage?html`<th scope="col" class="shipment-tracking-check"><input type="checkbox" data-tracking-select-all aria-label="Bu sayfadaki tüm kayıtları seç"></th>`:''}
    <th scope="col" class="shipment-tracking-order">Sipariş</th><th scope="col" class="shipment-tracking-market">Pazaryeri</th>
    <th scope="col" class="shipment-tracking-customer">Müşteri adı</th><th scope="col" class="shipment-tracking-sku">SKU/Adet</th><th scope="col" class="shipment-tracking-city">İl</th>
    ${invoice?html('<th scope="col" class="shipment-tracking-amount">Fatura tutarı</th>'):''}
    <th scope="col" class="shipment-tracking-delivery-status">Teslimat</th><th scope="col">Fatura durumu</th><th scope="col">İşlemler</th>
   </tr></thead>
   <tbody>${rows||''}</tbody>
  </table>
  ${rows?'':html`<div class="shipment-tracking-placeholder">${blank}</div>`}
 </div>${trackingFooter(model)}`;
}
