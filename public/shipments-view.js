import {t as i18nT,text as i18nText,html as i18nHtml,labelRows} from './i18n.js';
import {currentLocale} from './i18n.js';
import {workspaceIcon} from './workspace-icons.js';
import {shipmentDraftRows} from './shipment-edit-session.js';
import {shipmentOperations} from './shipment-operations.js';
import {cancellationState} from './order-cancellation.js';
import {availableShipmentMonths,shipmentMonthGroups,shipmentMonthLabel} from './shipment-calendar.js';
import {shipmentRowStatus,shipmentStatusLegend} from './shipment-status.js';
import {shipmentCopyButton} from './shipment-copy.js';
import {tableCopyLine} from './table-copy-button.js';
import {shipmentCustomerType} from './shipment-customer.js';
import {shipmentEditorRow,shipmentEditorActions} from './shipment-editor-view.js';
import {documentOutputActions} from './document-output-actions.js';
import {esc,money} from './ui.js';
import {shipmentAmountHidden} from './table-amounts.js';
import {shipmentSelectAll,shipmentBulkToolbar,shipmentRowSelection} from './shipment-bulk.js';
import {marketplaceLogo} from './marketplace-branding.js';
import {marketplaceVisible} from './marketplace-visibility.js';
import {shipmentFilterCount} from './shipment-filters.js';
import {shipmentTableLayout,isGroupedShipmentLayout} from './shipment-layout.js';
import {loadingState} from './content-state.js';
import {shipmentGroupedColumns,groupedShipmentCells} from './shipment-grouped-view.js';
import {shipmentInvoiceColumnsFor,invoiceShipmentCells} from './shipment-invoice-view.js';
import {shipmentPaymentDetails} from './shipment-payment-details.js';
import {shipmentDeliveryAddressText,shipmentDeliveryLocation} from './shipment-address-display.js';
export const shipmentCities=['Ankara','Eskişehir','Kayseri','Kırıkkale','Kırşehir','Nevşehir'];
export const shipmentProviders=[['all','Tüm şehirler'],['Hepsiburada','Hepsiburada'],['n11','N11'],['idefix','İdefix'],['PTT AVM','PttAVM'],['Pazarama','Pazarama'],['Koçtaş','Koçtaş'],['Trendyol','Trendyol']].filter(([name])=>marketplaceVisible(name));
export const shipmentMonths=availableShipmentMonths();
export const shipmentColumns=labelRows([['rowStatus','İşlemler'],['orderedAt','Sipariş Tarihi ve Saati'],['customerName','Müşteri Adı'],['recipientName','Alıcı Adı'],['phone','Tel No'],['deliveryAddress','Teslimat Adresi'],['province','İl'],['district','İlçe'],['productName','Ürün Adı'],['invoiceCents','Fatura Tutarı'],['quantity','Adet'],['invoiceName','Fatura Adı'],['invoiceAddress','Fatura Adresi'],['recipientEmail','Alıcı Mail'],['customerType','Müşteri Tipi']]);
export const shipmentDisplayColumns=(layout=shipmentTableLayout,productMode='skuQuantity')=>layout==='invoice'?shipmentInvoiceColumnsFor(productMode):isGroupedShipmentLayout(layout)?shipmentGroupedColumns:shipmentColumns;
export const shipmentsContent=()=>i18nHtml('<section class="shipment-workspace" data-shipments aria-label="Şehir dışı sevkiyatlar"></section>');
export function shipmentPagination(data,busy=false){
 if(!data||data.total<=data.pageSize)return '';
 const total=Math.ceil(data.total/data.pageSize),current=data.page+1;
 const pages=[...new Set([1,total,...Array.from({length:5},(_,i)=>current+i-2).filter(page=>page>=1&&page<=total)])].sort((a,b)=>a-b);
 const buttons=pages.map((page,index)=>i18nHtml`${index&&page-pages[index-1]>1?i18nHtml('<span class="shipment-page-gap" aria-hidden="true">…</span>'):''}<button type="button" class="btn shipment-page-number" data-shipment-page="${page-1}" aria-label="${page}. sayfa" ${page===current?'aria-current="page"':''} ${busy?'disabled':''}>${page}</button>`).join('');
 return i18nHtml`<nav class="shipment-pages" aria-label="Sevkiyat sayfaları"><button type="button" class="btn" data-shipment-page="${data.page-1}" ${data.page===0||busy?'disabled':''}>Önceki</button>${buttons}<button type="button" class="btn" data-shipment-page="${data.page+1}" ${current>=total||busy?'disabled':''}>Sonraki</button></nav>`;
}
export const shipmentCityLabel=city=>city==='all'?i18nT('Tüm şehirler'):city;
export function shipmentCityFilter(model,id){
 return i18nHtml`<label class="shipment-city-filter" for="${id}"><span>Teslimat adresi</span><select id="${id}" data-shipment-city-filter aria-label="Teslimat şehri"><option value="all" ${model.city==='all'?'selected':''}>Tüm şehirler</option>${(model.availableCities??shipmentCities).map(city=>i18nHtml`<option value="${city}" ${model.city===city?'selected':''}>${city}</option>`).join('')}</select></label>`;
}
export function shipmentProviderFilter(model){
 return i18nHtml`<label class="shipment-city-filter" for="shipment-provider-filter"><span>Pazaryeri</span><select id="shipment-provider-filter" data-shipment-provider-filter aria-label="Pazaryeri">${shipmentProviders.map(([value,label])=>i18nHtml`<option value="${esc(value)}" ${model.provider===value?'selected':''}>${value==='all'?i18nT('Tüm pazaryerleri'):label}</option>`).join('')}</select></label>`;
}
export function shipmentMonthFilter(model){
 return i18nHtml`<label class="shipment-city-filter shipment-month-filter" for="shipment-month-filter"><span>Ay</span><select id="shipment-month-filter" data-shipment-month-filter aria-label="Ay" required>${shipmentMonthGroups().map(group=>i18nHtml`<optgroup label="${group.year}">${group.months.map(month=>i18nHtml`<option value="${month.value}" ${month.value===model.month?'selected':''} ${month.disabled?'disabled':''}>${esc(month.label)}</option>`).join('')}</optgroup>`).join('')}</select></label>`;
}
export function shipmentRow(row,selected=false,canConfirm=false,operationsOpen=false,editMode=false,readOnly=false,layout=shipmentTableLayout,invoiceItems=[row],showCancellations=true,canInvoice=false,canShare=false,invoiceRights,productMode='skuQuantity'){
 const rowReadOnly=readOnly||(!showCancellations&&row.customerReadOnly);
 const status=row.marketplaceReturn?.returnedQuantity>0?'cancelled':shipmentRowStatus(row);
 const invoiced=invoiceItems.every(item=>item.invoiceWorkDone===true);
 const canMarkInvoice=canInvoice&&!rowReadOnly&&!editMode&&!row.draft;
 const invoiceStatus=invoiced?i18nHtml`<span>✓ Fatura düzenlendi</span>`:'';
 const invoiceButton=canMarkInvoice?(invoiced
  ?i18nHtml`<button type="button" class="btn" data-shipment-invoice="${Number(row.id)}" data-invoice-undo aria-label="Fatura işaretini geri al">×</button>`
  :i18nHtml`<button type="button" class="btn" data-shipment-invoice="${Number(row.id)}" aria-label="Fatura düzenlendi olarak işaretle">✓</button>`):'';
 const invoiceMark=content=>content?i18nHtml`<span class="shipment-invoice-mark">${content}</span>`:'';
 const invoiceAction=invoiceMark(invoiceStatus+invoiceButton);
 const classes=`${status?'shipment-row-'+status:''}${selected?' shipment-selected':''}${(row.cancellationMark||cancellationState(row))?' shipment-source-cancelled':''}${invoiced?' shipment-invoice-done':''}`;
 const editActions=editMode&&!rowReadOnly?i18nHtml`<div class="shipment-row-edit-actions"><button type="button" class="btn" data-shipment-edit-row="${Number(row.id)}">Düzenle</button><button type="button" class="btn" data-shipment-delete="${Number(row.id)}">Sil</button></div>`:'';
 const invoiceRecord=invoiceItems.find(item=>!item.draft&&!item.documentRestricted&&item.documents?.invoice)
  ||(invoiceRights?.canUpload?invoiceItems.find(item=>!item.draft&&!item.documentRestricted&&!item.customerReadOnly):null);
 const hasInvoice=Boolean(invoiceRecord?.documents?.invoice);
 const documentAction=!editMode&&invoiceRights?.canDocuments&&invoiceRecord
  ?i18nHtml`<button type="button" class="btn shipment-tax-invoice-action" data-shipment-document="invoice" data-shipment-document-row="${Number(invoiceRecord.id)}">${workspaceIcon('reports')}${hasInvoice?i18nT('Fatura aç'):i18nT('Fatura yükle')}</button>`:'';
 if(layout==='invoice'){
  const slip=canShare&&!editMode&&!row.draft&&!rowReadOnly?i18nHtml`<div class="shipment-cargo-slip"><button type="button" class="btn" data-cargo-slip="${Number(row.id)}">${workspaceIcon('print')}Yazdır</button></div>`:'';
  const actions=slip?i18nHtml`${invoiceStatus?i18nHtml`<div class="shipment-cargo-invoice-state">${invoiceStatus}</div>`:''}
   <div class="shipment-cargo-actions">${invoiceMark(invoiceButton)}${slip}</div>`:invoiceAction;
  return i18nHtml`<tr data-shipment-row="${Number(row.id)}" class="${classes}">${invoiceShipmentCells(row,editActions,invoiceItems,actions,documentAction,productMode)}</tr>`;
 }
 return i18nHtml`<tr data-shipment-row="${Number(row.id)}" class="${classes}">${(isGroupedShipmentLayout(layout)?shipmentColumns.slice(0,1):shipmentColumns).map(([key,label])=>{
  if(key==='rowStatus')return i18nHtml`<td class="shipment-check shipment-cell-rowStatus" data-label="İşlemler">${editActions||i18nHtml`<div class="shipment-row-tools">${rowReadOnly?'':shipmentRowSelection(row,selected)}${shipmentOperations(row,{selected,canConfirm,open:operationsOpen,readOnly:rowReadOnly,showCancellations})}</div>`}${(row.dataIssues||[]).length?i18nHtml`<small class="shipment-data-warning">Eksik bilgi</small>`:''}${row.draft?i18nHtml('<small>Kaydedilmedi</small>'):''}</td>`;
  let value=row[key],content,paymentDetails='';
  const copy=key!=='invoiceCents'&&String(value??'').trim()?shipmentCopyButton(key,label):'';
  if(key==='deliveryAddress')return i18nHtml`<td class="shipment-cell-deliveryAddress" data-label="${label}">${tableCopyLine(esc(shipmentDeliveryAddressText(row)),copy)}${shipmentDeliveryLocation(row)}</td>`;
  if(key==='customerName'&&row.customerNameSource==='recipient')content=i18nHtml`<small>Sipariş sahibi bildirilmedi</small><br>Alıcı: ${esc(value||'—')}`;
  if(key==='quantity'&&row.marketplaceReturn?.returnedQuantity>0)content=i18nHtml`${esc(value)}<small class="shipment-return-quantity">İade: ${esc(row.marketplaceReturn.returnedQuantity)}<br>Kalan: ${esc(row.marketplaceReturn.remainingQuantity)}</small>`;
  if(key==='customerType')content=shipmentCustomerType(row).split('\n').map(value=>i18nHtml`<span class="shipment-customer-line">${esc(value||'—')}</span>`).join('')||'—';
  if(key==='invoiceCents'){
   if(shipmentAmountHidden(row))return i18nHtml`<td class="shipment-cell-${key}" data-label="${label}">—</td>`;
   if(Number.isSafeInteger(value))value=money(value);
   paymentDetails=shipmentPaymentDetails([row]);
   if(paymentDetails)content=i18nHtml`<small class="shipment-grouped-secondary">Fatura tutarı</small><strong>${esc(value??'—')}</strong>`;
  }
  if(key==='orderedAt'&&value){const date=new Date(value);if(Number.isFinite(date.getTime()))content=i18nHtml`<time datetime="${esc(date.toISOString())}"><span>${esc(date.toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric'}))}</span><span>${esc(date.toLocaleTimeString(currentLocale(),{timeZone:'Europe/Istanbul',hour:'2-digit',minute:'2-digit'}))}</span></time>`;}
  if(key==='recipientName'&&!String(value??'').trim())content=i18nHtml('<span class="shipment-data-warning">Alıcı adı eksik</span>');
  if(!content){content=esc(value==null||value===''?'—':value);if(key==='recipientEmail')content=content.replace(/([@._-])/g,i18nHtml('$1<wbr>'));}
  return i18nHtml`<td class="shipment-cell-${key}" data-label="${label}">${paymentDetails}${tableCopyLine(content,copy)}${key==='invoiceCents'?invoiceAction:''}</td>`;
 }).join('')}${isGroupedShipmentLayout(layout)?groupedShipmentCells(row,invoiceAction,documentAction):''}</tr>`;
}
export function shipmentExportActions(model){
 if(model.readOnly||model.canShare===false)return '';
 const disabled=!model.data?.items?.length||model.busy||model.exporting;
 return i18nHtml`<div class="shipment-file-actions" role="group" aria-label="Paylaş ve dışa aktar">
  ${documentOutputActions({excel:{attr:'data-shipment-export',value:'xlsx',disabled},pdf:{attr:'data-shipment-export',value:'pdf',disabled},print:{attr:'data-shipment-print',disabled},share:{attr:'data-shipment-share',disabled}})}
 </div>`;
}
export function shipmentSearchForm(model){
 return i18nHtml`<form class="shipment-search" data-shipment-search role="search" aria-label="Seçili tablodaki kayıtlarda ara"><label for="shipment-query">Kayıtlarda ara</label><div class="shipment-search-controls"><input type="search" id="shipment-query" data-shipment-query name="query" maxlength="120" autocomplete="off" placeholder="Ad, telefon, ürün veya adres…" value="${esc(model.searchDraft||'')}"><button type="submit" class="btn primary" ${model.busy?'disabled':''}>Ara</button>${shipmentProviderFilter(model)}${shipmentCityFilter(model,'shipment-city-filter')}${shipmentMonthFilter(model)}${shipmentExportActions(model)}${model.query?i18nHtml('<button type="button" class="btn" data-shipment-clear>Temizle</button>'):''}</div></form>`;
}
export function shipmentTable(model,layout=model.layout??shipmentTableLayout){
 const data=model.data,items=shipmentDraftRows(model),month=shipmentMonthLabel(model.month),filtered=!!model.query||!!shipmentFilterCount(model.filters);
 const headers=shipmentDisplayColumns(layout,model.productMode??'fullName'),invoice=layout==='invoice',grouped=invoice||isGroupedShipmentLayout(layout);
 const editor=()=>shipmentEditorRow(model.editor,model.month,model.availableCities??shipmentCities,headers.length);
 const sourceGroups=invoice?data?.invoiceGroups:null;
 const byId=new Map(items.map(row=>[row.id,row]));
 const displayGroups=sourceGroups&&!model.editMode?sourceGroups.map(ids=>ids.map(id=>byId.get(id)).filter(Boolean)).filter(group=>group.length):items.map(row=>[row]);
 const rows=displayGroups.map(group=>{
  const row=group[0];
  return shipmentRow(row,model.selectedIds?.has(row.id),model.canConfirm,false,model.editMode,model.readOnly,layout,group,model.canSetCancellation??model.showCancellations,model.canInvoice,Boolean(sourceGroups)&&model.canShare,model.documentPermissions?.invoice,model.productMode??'fullName')+(!model.readOnly&&model.editor?.id===row.id?editor():'');
 }).join('');
 const shown=sourceGroups?.length??items.length;
 const empty=i18nHtml`<tr><td colspan="${headers.length}" class="shipment-empty-cell">${model.loading||model.busy?loadingState('Kayıtlar yükleniyor…',{compact:true}):i18nHtml`<div class="shipment-empty"><strong>${model.error?i18nT('Liste yüklenemedi'):filtered?i18nT('Arama ve filtrelerle eşleşen kayıt bulunamadı.'):i18nT('Bu ay için henüz kayıt yok.')}</strong><span>${model.error?i18nT('Yenile düğmesiyle tekrar deneyebilirsiniz.'):filtered?i18nT('Aramayı veya filtrelerinizi değiştirebilirsiniz.'):i18nT('Paylaşacağınız sevkiyat bilgileri burada listelenecek.')}</span></div>`}</td></tr>`;
 return i18nHtml`<div class="shipment-table-card"><div class="shipment-table-heading"><div><span class="eyebrow">${esc(shipmentCityLabel(model.city))} · ${esc(model.provider==='all'?i18nT('Tüm pazaryerleri'):model.provider)}</span><h2>${esc(month)}</h2></div><div><span class="shipment-count" role="status">${data?(filtered?data.total+i18nT(' sonuç / ')+data.totalRecords+i18nT(' kayıt'):data.total+i18nT(' kayıt')):i18nT('Yükleniyor…')}</span></div></div>
 ${shipmentSearchForm(model)}${model.readOnly||invoice?'':shipmentBulkToolbar(model)}
 <p class="shipment-message" role="status">${esc(model.message)}</p></div>
 <div class="table-status-bar shipment-table-tools">${shipmentStatusLegend(model.showCancellations||items.some(row=>row.cancellationMark))}${model.readOnly?'':shipmentEditorActions(model)}</div>
 <p class="shipment-scroll-hint">Diğer sütunları görmek için tabloyu sağa veya sola kaydırın.</p>
 <div class="shipment-table-card"><div class="shipment-scroll ${grouped?'shipment-layout-grouped':''}${invoice?' shipment-layout-invoice':''}" tabindex="0" role="region" aria-label="Sevkiyat tablosu"><table data-copyable-table data-shipment-layout="${esc(layout)}">
 <caption class="shipment-sr-only">${esc(shipmentCityLabel(model.city))} ${esc(model.provider==='all'?i18nT('Tüm pazaryerleri'):model.provider)} ${esc(month)} sevkiyatları</caption>
 <thead><tr>${headers.map(([key,label])=>i18nHtml`<th scope="col" class="shipment-head-${key}">${label}${key==='rowStatus'&&!model.readOnly?shipmentSelectAll(model):''}</th>`).join('')}</tr></thead>
 <tbody>${!model.readOnly&&!model.editor?.id?editor():''}${rows||empty}</tbody></table></div>
 <div class="shipment-table-foot"><span>${shown?i18nText`${data.page*data.pageSize+1}–${data.page*data.pageSize+shown} / ${data.total} kayıt`:i18nT('Gösterilecek kayıt yok.')}</span>${shipmentPagination(data,model.busy)}</div></div>`;
}
export function shipmentSelection(model){
 const month=shipmentMonthLabel(model.month);
 const picker=model.picker?i18nHtml`<div class="shipment-picker-panel" id="shipment-picker-panel" role="region" aria-label="Ay seçimi"><div class="shipment-picker-heading"><div><span class="eyebrow">${esc(model.draftProvider)}</span><h3>Ay seçin</h3></div><button type="button" class="btn" data-shipment-close>← Geri</button></div><div class="shipment-month-options">${shipmentMonths.map(item=>i18nHtml`<button type="button" data-shipment-month="${item.value}" ${model.provider===model.draftProvider&&model.month===item.value?'aria-current="true"':''}>${item.label}</button>`).join('')}</div></div>`:'';
 return i18nHtml`<div class="shipment-picker" data-shipment-picker><div class="shipment-provider-picker"><div class="shipment-providers" aria-label="Pazaryeri seçimi">${shipmentProviders.map(([value,label])=>i18nHtml`<button type="button" data-shipment-provider="${esc(value)}" aria-pressed="${(model.picker?model.draftProvider:model.provider)===value}">${value==='all'?i18nHtml`<span class="shipment-all-cities-icon" aria-hidden="true">▦</span><strong>${label}</strong>`:marketplaceLogo(value)||i18nHtml`<strong>${label}</strong>`}</button>`).join('')}</div>${picker}</div>${model.month?i18nHtml`<div class="shipment-selection-summary"><span>${esc(model.provider==='all'?i18nT('Tüm pazaryerleri'):model.provider)} <span aria-hidden="true">›</span> ${esc(month)}</span></div>`:''}</div>`;
}
export function shipmentsView(model){
 return model.month?i18nHtml`<section data-shipment-table aria-label="Sevkiyat kayıtları">${shipmentTable(model)}</section>`:'';
}
