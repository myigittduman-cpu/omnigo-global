import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
import {visibleShipmentStatuses} from './shipment-status.js';
import {api} from './api.js';
import {confirmShipmentStatus} from './shipment-status-confirmation.js';

const selectableShipmentRows=model=>(model.data?.items||[]).filter(row=>model.canSetCancellation!==false||!row.customerReadOnly);
export const selectedShipmentRows=model=>selectableShipmentRows(model).filter(row=>model.selectedIds?.has(row.id));
export function shipmentSelectAll(model){
 const rows=selectableShipmentRows(model),all=rows.length>0&&selectedShipmentRows(model).length===rows.length;
 return i18nHtml`<button type="button" class="shipment-select-all" data-shipment-select-all aria-pressed="${all}" ${!rows.length||model.busy||model.writing||model.editor?'disabled':''}>${all?i18nT('Seçimi bırak'):i18nT('Tümünü seç')}</button>`;
}
export function shipmentRowSelection(row,selected){
 return i18nHtml`<label class="shipment-row-selection"><input type="checkbox" data-shipment-select="${Number(row.id)}" aria-label="${esc(row.customerName||'Sipariş '+row.id)} satırını seç" ${selected?'checked':''}><span>Seç</span></label>`;
}
export function shipmentBulkToolbar(model){
 const rows=selectedShipmentRows(model),disabled=model.busy||model.writing||model.editor||model.exporting;
 const options=visibleShipmentStatuses(model.canSetCancellation??model.showCancellations);
 return i18nHtml`<div class="shipment-bulk-toolbar ${rows.length?'has-selection':''}"><div class="shipment-mobile-select">${shipmentSelectAll(model)}</div>${rows.length?i18nHtml`<strong>${rows.length} satır seçildi</strong><div class="shipment-bulk-actions" role="group" aria-label="Seçilen satırların durumunu değiştir">${options.map(status=>i18nHtml`<button type="button" data-shipment-bulk-status="${status.value}" ${disabled?'disabled':''}><i class="shipment-status-swatch shipment-status-${status.value}" aria-hidden="true"></i>${status.label}</button>`).join('')}${i18nHtml`<button type="button" data-shipment-bulk-status="" ${disabled?'disabled':''}>Durumu temizle</button>`}</div>`:''}</div>`;
}
export function bindShipmentBulk({root,model,current,endpoint,paintTable,refresh,invalidate,canLeave}){
 model.selectedIds=new Set();
 const prune=()=>{const ids=new Set(selectableShipmentRows(model).map(row=>row.id));model.selectedIds=new Set([...model.selectedIds].filter(id=>ids.has(id)));};
 root.addEventListener('change',event=>{
  const input=event.target;if(!input.matches('[data-shipment-select]')||!current())return;
  if(model.canSelect===false||model.busy||model.writing||model.editor){paintTable();return;}
  const id=Number(input.dataset.shipmentSelect);if(!selectableShipmentRows(model).some(row=>row.id===id))return;
  if(input.checked)model.selectedIds.add(id);else model.selectedIds.delete(id);paintTable();
 });
 return {prune,async click(button){
  if(!button.hasAttribute('data-shipment-select-all')&&!button.hasAttribute('data-shipment-bulk-status'))return false;
  if(model.canSelect===false||model.busy||model.writing||model.exporting||!canLeave())return true;
  if(button.hasAttribute('data-shipment-select-all')){
   const rows=selectableShipmentRows(model);model.selectedIds=selectedShipmentRows(model).length===rows.length?new Set():new Set(rows.map(row=>row.id));paintTable();return true;
  }
  if(model.canBulkStatus===false)return true;
  const rows=selectedShipmentRows(model);if(!rows.length)return true;
  model.writing=true;invalidate();model.message=i18nT('Güncel durumlar kontrol ediliyor…');paintTable();
  try{
   const preview=await confirmShipmentStatus({endpoint,rows,value:button.dataset.shipmentBulkStatus,current});
   if(!preview){model.message=i18nT('Durum değişikliği yapılmadı.');return true;}
   const result=await api(endpoint('status'),'POST',{value:preview.value,items:preview.items.map(({id,version,confirmation})=>({id,version,confirmation}))});
   if(current()){model.selectedIds.clear();model.message=i18nText`${result.updated} satır güncellendi.`;}
  }catch(error){if(current())model.message=error.message;}
  finally{model.writing=false;if(current())await refresh();}
  return true;
 }};
}
