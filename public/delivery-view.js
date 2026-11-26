import {paginationButtons} from './pagination-controls.js';
import {deliveryCardCities} from './delivery-scope.js';
import {nationalDeliveryQueues} from './delivery-national-scope.js';
import {deliveryTableScope} from './delivery-heading.js';
import {loadingState} from './content-state.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {deliverySelectionKey} from './delivery-dispatch-selection.js';
import {deliverySummary,summaryText} from './export-summary.js';
import {currentLocale} from './i18n.js';
import {fulfillmentNote} from './shipment-fulfillment.js';
import {deliveryNeedsVerification,deliveryVerificationNotice} from './delivery-document-guard.js';
import {workspaceIcon} from './workspace-icons.js';
import {esc,icon,platform} from './ui.js';
import {isDeliveryQueue,deliveryQueueButtons,deliveryQueueFooter} from './delivery-queues.js';
import {documentOutputActions} from './document-output-actions.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {summarizeOrderProducts} from './order-product-summary.js';
import {missingValue,correctionNote} from './delivery-correction.js';
import {deliveryCities,availableDeliveryCities,deliveryCityFilter,deliveryCityLabel} from './delivery-city-filter.js';
export {deliveryCities} from './delivery-city-filter.js';
export const deliveryProviders=['Hepsiburada','n11','Koçtaş','Pazarama','idefix','PTT AVM','Trendyol'].filter(marketplaceVisible);
export const deliveryPageSize=20;
export const deliveryPageLimit=queue=>queue==='all'?'all':deliveryPageSize;
export const deliveryContent=()=>i18nHtml`<section class="delivery-workspace" data-delivery><p role="status">Teslimat kayıtları yükleniyor…</p></section>`;
export const ready=o=>!o.issues.length&&o.active&&deliveryCities.includes(o.city)&&!deliveryNeedsVerification(o);
function deliveryCityChoices(model){
 if(model.shippingMode==='nationwide')return nationalDeliveryQueues(model);
 const data=model.navigation??model.data;
 const selected=city=>!isDeliveryQueue(model)&&(model.cities?model.cities.includes(city):model.city===city);
 return i18nHtml`<div class="delivery-cities" aria-label="Teslimat ili">${['all',...deliveryCardCities(model),'Diğer / Kontrol'].map(city=>i18nHtml`<button data-city="${esc(city)}" aria-pressed="${selected(city)}" class="${selected(city)?'selected':''}"><span class="delivery-city-icon">${workspaceIcon(city==='Diğer / Kontrol'?'warning':'truck')}</span><span class="delivery-city-name">${esc(city==='all'?i18nT(model.shippingMode==='nationwide'?'Tüm Türkiye':'Tüm Şehirler'):i18nT(city))}</span><strong>${data?.cities.find(c=>c.name===city)?.count??'—'} sipariş · ${data?.cities.find(c=>c.name===city)?.productCount??'—'} parça</strong><small>${city==='all'?i18nT('Tüm illerdeki aktif siparişler'):city==='Diğer / Kontrol'?i18nT('Eksik bilgi veya kapsam dışı il'):i18nT('Bu ile ait aktif siparişler')}</small></button>`).join('')}${deliveryQueueButtons(model)}</div>`;
}
const deliveryPageTitle=model=>isDeliveryQueue(model)?i18nT('İptal Edilen Siparişler'):deliveryCityLabel(model);
export function deliveryView(model){
 const queue=isDeliveryQueue(model);
 const data=model.data?{...model.data,items:model.data.items.filter(o=>queue?o.queue===model.queue:o.active)}:null,eligible=data?.items.filter(ready)||[];
 const selectedSummary=deliverySummary((data?.items||[]).filter(order=>model.selected.has(deliverySelectionKey(order))));
 return i18nHtml`<p class="delivery-feedback" role="status" aria-live="polite">${esc(model.message||'')}</p>
 ${deliveryCityChoices(model)}<h2>${esc(deliveryPageTitle(model))}</h2>
 ${data?.items.some(deliveryNeedsVerification)?i18nHtml`<p class="delivery-missing" role="note">Bu sayfada ${data.items.filter(deliveryNeedsVerification).length} siparişin gerçek durumu doğrulanamadı. Bu kayıtlar listede kalır; doğrulanana kadar PDF, Excel ve paylaşıma dahil edilmez.</p>`:''}

 <div class="delivery-filters">${queue||model.shippingMode==='nationwide'?'':deliveryCityFilter(model)}<label>Pazaryeri<select data-provider><option value="all">Tüm pazaryerleri</option>${deliveryProviders.map(p=>i18nHtml`<option ${p===model.provider?'selected':''}>${p}</option>`).join('')}</select></label>${queue||model.readOnly?'':i18nHtml`<label>Sevkiyat tarihi<input type="date" data-date value="${esc(model.date)}" required></label>`}</div>
 <form class="delivery-search" data-delivery-search role="search"><label>Kayıtlarda ara<input type="search" name="query" maxlength="120" placeholder="Sipariş, alıcı, telefon veya ürün…" value="${esc(model.query||'')}"></label><label>Sipariş başlangıç tarihi<input type="date" name="from" value="${esc(model.from||'')}"></label><label>Sipariş bitiş tarihi<input type="date" name="to" value="${esc(model.to||'')}"></label><button class="btn">Filtrele</button></form>
 ${queue||model.readOnly?'':selectionToolbar(model,eligible,selectedSummary)}
 ${queue&&data?.hasMore?i18nHtml`<p class="delivery-note">Her sayfada ${deliveryPageLimit(model.queue)} kayıt gösterilir. Diğer kayıtlar için sonraki sayfayı kullanın.</p>`:''}
 ${deliveryTableScope(model)}
 <div class="delivery-orders">${!data&&model.loading?loadingState('Teslimat kayıtları yükleniyor…'):data?.items.length?data.items.map(o=>orderView(o,model)).join(''):i18nHtml`<div class="empty-state"><h3>${queue?({delayed:i18nT('Geciken sipariş yok'),shipped:i18nT('Kargodaki sipariş yok'),cancelled:i18nT('İptal edilen sipariş yok'),delivered:i18nT('Teslim edilen sipariş yok'),review:i18nT('Teyit bekleyen sipariş yok')})[model.queue]:i18nT('Bu şehirde sipariş yok')}</h3><p>${queue?i18nT('Seçili pazaryerinde bu koşula uyan sipariş bulunmuyor.'):i18nT('Aktif siparişler teslimat adresindeki ile göre burada listelenir.')}</p></div>`}</div>
 ${queue&&(model.page||data?.hasMore)?pagination(model,data):''}
 <p class="delivery-note">${model.readOnly?i18nT('Bu liste otomatik güncellenir. Sipariş bilgilerini yalnız yönetici düzenleyebilir.'):i18nT('Eksik bilgili siparişler evrağa eklenmez. PDF ve Excel indirmek sipariş durumunu değiştirmez; aynı seçimi tekrar indirebilirsiniz.')}</p>`;
}
function selectionToolbar(model,eligible,summary){
 const disabled=!model.selected.size||model.canExport===false;
 return i18nHtml`<div class="delivery-selection">
  <label><input type="checkbox" data-select-all ${eligible.length?eligible.every(o=>model.selected.has(deliverySelectionKey(o)))?'checked':'':'disabled'}> Tümünü seç</label>
  <span data-selection-count>${esc(summaryText(summary))}</span>
  <div class="delivery-selection-actions" role="group" aria-label="Sipariş ve evrak işlemleri">
   ${model.canExport===false?'':documentOutputActions({excel:{attr:'data-excel',disabled},pdf:{attr:'data-create',disabled},print:{attr:'data-delivery-print',disabled},share:{attr:'data-delivery-share',disabled}})}
  </div>
 </div>`;
}
export function orderDate(value){
 if(!value)return i18nT('Sipariş tarihi iletilmedi');
 const normalized=/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(value)?value.replace(' ','T')+'+03:00':value;
 const date=new Date(normalized);return Number.isNaN(date.getTime())?i18nT('Sipariş tarihi iletilmedi'):date.toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function orderView(o,model){
 const status=o.queue==='delivered'?i18nT('Sipariş Tamamlandı'):o.queue==='cancelled'?((o.operationalCancellation??o.cancellation)?.partial?i18nT('Kısmi iptal'):i18nT('İptal edildi')):o.queue==='delayed'?i18nT('Geciken sipariş'):o.queue==='shipped'?i18nT('Gerçekten sevk edildi'):o.queue==='review'?'Sevkiyat teyidi bekliyor':o.deliveryStatus||o.status;
 return i18nHtml`<article class="delivery-order ${o.cancellationMark?'delivery-order-cancelled':''} ${o.queue?`delivery-order-${o.queue}`:''}"><header><label class="delivery-order-choice">${o.queue||model.readOnly?'':i18nHtml`<input type="checkbox" data-order="${esc(deliverySelectionKey(o))}" ${ready(o)?'':'disabled'} ${model.selected.has(deliverySelectionKey(o))?'checked':''}>`}<span>${platform(o.provider)}<small>${o.queue==='delivered'?i18nText`Sevkiyat kaydı #${esc(o.shipmentId)}`:i18nText`Sipariş ${missingValue(o.orderNumber,i18nT('Sipariş numarası'))}`}</small></span></label><div class="delivery-order-meta"><time datetime="${esc(o.createdAt)}">${esc(orderDate(o.createdAt))}</time><span class="badge status-badge status-${esc(o.fulfillmentStatus||o.queue||'pending')}">${esc(status)}</span>${o.cancellationMark?i18nHtml`<small>${i18nT('Kısmi iptal')}</small>`:''}${o.selectionRecords&&o.marketplaceStatusText?i18nHtml`<small>Pazaryeri: ${esc(i18nT(o.marketplaceStatusText))}</small>`:''}</div></header><div class="delivery-order-body"><div><small>${o.provider==='PTT AVM'&&!o.manualCorrections?.buyerName?i18nT('Teslimat alıcısı'):i18nT('Siparişi veren kişi')}</small><strong>${missingValue(o.buyerName,o.provider==='PTT AVM'?i18nT('Teslimat alıcısı'):i18nT('Siparişi veren kişi'))}</strong><span>${missingValue(o.phone,'Teslimat telefonu')}</span>${o.provider==='PTT AVM'&&!o.manualCorrections?.buyerName?i18nHtml('<span>Sipariş sahibi PTT AVM API’sinde bildirilmedi.</span>'):i18nHtml`<span>Alıcı: ${missingValue(o.details?.recipientName||o.recipientName,i18nT('Alıcı adı'))}</span>`}</div><div><small>Teslimat adresi</small><p>${missingValue(o.address,i18nT('Teslimat adresi'))}</p>${o.multipleAddresses?i18nHtml`<small>Bu siparişte birden fazla teslimat adresi var. Evrakta ayrı gösterilir.</small>`:''}</div><div><small>${o.queue==='cancelled'?i18nT('İptal edilen ürünler'):i18nT('Ürünler')}</small><ul>${summarizeOrderProducts(o.queueProducts||o.products).map(p=>i18nHtml`<li><strong class="${p.quantity==null||p.quantity<1?'delivery-missing':''}">${p.quantity==null?i18nT('Miktar belirtilmemiş'):`${esc(p.quantity)} adet`}</strong> ${missingValue(p.title,i18nT('Ürün adı'))}${p.sku?i18nHtml`<span class="delivery-sku">${esc(p.sku)}</span>`:''}</li>`).join('')}</ul></div></div>${(o.fulfillmentChecks||[]).length?i18nHtml`<div class="delivery-fulfillment">${o.fulfillmentChecks.map(row=>i18nHtml`<div><small>${esc(row.productName)}</small>${fulfillmentNote(row,true)}</div>`).join('')}</div>`:''}${deliveryVerificationNotice(o)}${correctionNote(o,!model.readOnly)}${o.queue?deliveryQueueFooter(o):''}</article>`;
}
function pagination(model,data){return i18nHtml`<div class="delivery-pages"><button class="btn" data-page="${model.page-1}" ${model.page===0?'disabled':''}>Önceki</button>${paginationButtons({page:model.page,count:Math.max(model.page+1+Number(Boolean(data?.hasMore)),Math.ceil((data?.total||0)/deliveryPageLimit(model.queue))),attribute:"data-page",disabled:model.busy})}<span>Sayfa ${model.page+1}</span><button class="btn" data-page="${model.page+1}" ${!data?.hasMore?'disabled':''}>Sonraki</button></div>`;}


