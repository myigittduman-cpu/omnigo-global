import {t as i18nT} from './i18n.js';
import {currentLocale} from './i18n.js';
import {documentFilename} from './document-filename.js';
import {shipmentStatusLabel} from './shipment-status.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {shipmentMonths,shipmentCityLabel} from './shipments-view.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';
import {saveReportFile,downloadStatus} from './report-download.js';
const text=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'');
export function shipmentExportValue(row,key){
 if(key==='deliveryAddress'){
  const address=text(row.deliveryAddress).trim();
  const location=[['İl',row.province],['İlçe',row.district]]
   .map(([label,value])=>[i18nT(label),text(value).trim()]).filter(([,value])=>value);
  const place=location.length?location.map(([label])=>label).join(' / ')+' · '+location.map(([,value])=>value).join(' / '):'';
  return [address,place].filter(Boolean).join('\n');
 }
 if(['invoiceAddress','customerType','taxNumber','taxOffice'].includes(key))return text(shipmentInvoiceFields(row)[key]);
 if(['recipientName','orderNumber'].includes(key)&&!String(row[key]??'').trim())return key==='recipientName'?i18nT('Alıcı adı eksik'):i18nT('Sipariş numarası doğrulanamadı');
 if(key==='rowStatus')return shipmentStatusLabel(row);
 if(key==='invoiced')return row[key]?'Kesildi':'Kesilmedi';
 if(key==='cancelled')return row[key]?i18nT('İptal edildi'):i18nT('İptal edilmedi');
 if(key==='shipped')return row[key]?'Sevk edildi':'Sevkiyat bekleniyor';
 if(key==='orderedAt')return new Date(row[key]).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',dateStyle:'short',timeStyle:'short'});
 if(key==='invoiceCents'){const amount=shipmentTableAmountCents(row);return amount===null?null:amount/100;}
 if(key==='quantity')return row[key];
 return text(row[key]);
}
export const shipmentExportTitle=data=>`${shipmentCityLabel(data.city)} · ${data.provider==='all'?i18nT('Tüm pazaryerleri'):data.provider} · ${shipmentMonths.find(month=>month.value===data.month)?.label||data.month}`;
export const shipmentExportFilename=(data,type,downloadedAt=new Date())=>documentFilename({company:data.company,city:data.city,provider:data.provider,kind:'shipment',type,downloadedAt});
function scopeDescription(data){
 const filters=data.filters||{},labels={invoiced:'Fatura',cancelled:i18nT('İptal'),shipped:'Sevk',rowStatus:i18nT('Sipariş durumu'),customerType:i18nT('Müşteri tipi'),dateFrom:i18nT('Başlangıç'),dateTo:i18nT('Bitiş')};
 return [data.query?'Arama: '+data.query:'',...Object.entries(filters).filter(([,value])=>value).map(([key,value])=>`${labels[key]}: ${key==='rowStatus'?shipmentStatusLabel({rowStatus:value}):value==='yes'?i18nT('Evet'):value==='no'?i18nT('Hayır'):value}`)].filter(Boolean).join(' · ')||i18nT('Seçili ayın tüm kayıtları');
}
import {activeShipmentExport} from './shipment-export-rows.js';
export async function createShipmentWorkbook(input){
 const data=activeShipmentExport(input);
 const {buildShipmentWorkbook}=await import('./shipment-workbook.js');
 return buildShipmentWorkbook(data,{title:shipmentExportTitle(data),scope:scopeDescription(data)});
}
export async function createShipmentPdf(input){
 const data=activeShipmentExport(input);
 const {createShipmentDocument}=await import('./shipment-pdf.js');
 return createShipmentDocument(data,{title:shipmentExportTitle(data),scope:scopeDescription(data),readValue:shipmentExportValue});
}
export async function downloadShipments(data,type,isCurrent,destination=null,selectedFilename=null,onFileReady){
 if(!data.items.length)throw new Error(i18nT('Dışa aktarılacak kayıt bulunamadı.'));
 const filename=selectedFilename||shipmentExportFilename(data,type);
 const blob=type==='xlsx'?await createShipmentWorkbook(data):await createShipmentPdf(data);
 if(isCurrent())onFileReady?.({blob,filename});
 return downloadStatus(await saveReportFile(blob,filename,isCurrent,globalThis,destination,{browserDownload:true}));
}
