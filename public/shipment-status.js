import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';

export const shipmentStatuses=[
 {value:'cancelled',label:'İptal Edildi',color:'#d86b70',background:'#f3dada',foreground:'#692c32'},
 {value:'shipped',label:'Sevk Edildi',color:'#66a6e0',background:'#dceafa',foreground:'#254665'},
 {value:'invoiced',label:'Faturası Kesildi',color:'#e6bd4b',background:'#f6ebbf',foreground:'#4d3c10'},
 {value:'completed',label:'Sipariş Tamamlandı',color:'#42a66a',background:'#d5efde',foreground:'#1f5033'},
];
export const shipmentRowStatus=row=>{
 if(row.cancellationMark==='cancelled')return 'cancelled';
 const status=row.displayRowStatus??row.rowStatus??(row.cancelled?'cancelled':row.shipped?'shipped':'');
 return status==='delivered'?'completed':status;
};
export const shipmentStatusLabel=row=>row.cancellationMark==='partial'?i18nT('Kısmi iptal'):row.marketplaceReturn?.returnedQuantity>0?(row.marketplaceReturn.partial?i18nText`Kısmi iade · ${row.marketplaceReturn.returnedQuantity} adet`:i18nT('İade onaylandı')):row.marketplaceDelivered===true?i18nT('Teslim edildi'):i18nT(shipmentStatuses.find(status=>status.value===shipmentRowStatus(row))?.label||'Sevk bekliyor');
export const visibleShipmentStatuses=(showCancellations=true)=>showCancellations?shipmentStatuses:shipmentStatuses.filter(status=>status.value!=='cancelled');
export function shipmentStatusLegend(showCancellations){
 return i18nHtml`<div class="shipment-status-legend" aria-label="Satır renklerinin anlamları">${visibleShipmentStatuses(showCancellations).map(status=>i18nHtml`<span><i class="shipment-status-swatch shipment-status-${status.value}" aria-hidden="true"></i> (${i18nT(status.label)})</span>`).join('')}</div>`;
}
export function shipmentStatusControls(row,showCancellations){
 const selected=shipmentRowStatus(row),name=esc(row.customerName||'Sipariş '+row.id),id=Number(row.id);
 const options=[{value:'',label:'Sevk bekliyor'},...visibleShipmentStatuses(showCancellations)];
 return i18nHtml`<div class="shipment-status-editor"><label for="shipment-status-${id}">Sipariş durumu</label><select id="shipment-status-${id}" data-shipment-status-select data-id="${id}" aria-label="${name} · Sipariş durumu">${options.map(status=>i18nHtml`<option value="${status.value}" ${selected===status.value?'selected':''} ${(row.dispatchTracked&&['','shipped'].includes(status.value))||(status.value==='invoiced'&&!row.documents?.invoice)?'disabled':''}>${i18nT(status.label)}</option>`).join('')}</select><button type="button" class="btn primary" data-shipment-status-save="${id}">Durumu kaydet</button>${row.dispatchTracked?i18nHtml`<small>Gerçek sevk durumunu Şehir Dışı Teslimatlar → Sevkiyat Onayı bölümünden değiştirin.</small>`:''}</div>`;
}
