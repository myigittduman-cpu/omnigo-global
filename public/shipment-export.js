import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {companyExportLogo,drawCompanyPdfLogo,exportCompanyName} from './company-export-brand.js';
import {shipmentSummary,summaryText} from './export-summary.js';
import {documentFilename} from './document-filename.js';
import {shipmentStatusLabel,shipmentRowStatus,shipmentStatuses} from './shipment-status.js';
import {cancellationState} from './order-cancellation.js';
import {shipmentTableAmountCents} from './table-amounts.js';
const rowPalette=row=>shipmentStatuses.find(status=>status.value===(cancellationState(row)||row.marketplaceReturn?.returnedQuantity>0?'cancelled':shipmentRowStatus(row)));
const rgb=hex=>[1,3,5].map(index=>parseInt(hex.slice(index,index+2),16));
import {shipmentMonths,shipmentCityLabel} from './shipments-view.js';
import {shipmentExportColumns as exportColumns,shipmentInvoiceFields} from './shipment-export-fields.js';
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
 const [{jsPDF,autoTable},{default:font},logo]=await Promise.all([import('./vendor/report-pdf-070.js'),import('./vendor/report-font-070.js'),companyExportLogo(data.company)]);
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a3'});doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');doc.setProperties({title:shipmentExportTitle(data),creator:'OmniGoGlobal'});
 const widths=[24,25,28,24,48,44,10,24,25,48,20,24,26,30];
 const body=data.items.map(row=>exportColumns.map(([key])=>{const value=shipmentExportValue(row,key);return key==='invoiceCents'?(value===null?'—':value.toLocaleString(currentLocale(),{minimumFractionDigits:2,maximumFractionDigits:2})+' TL'):value??'';}));
 autoTable(doc,{margin:{top:35,left:10,right:10,bottom:14},theme:'grid',styles:{font:'NotoSans',fontStyle:'normal',fontSize:8,cellPadding:1.8,overflow:'linebreak',lineWidth:.15,lineColor:[201,177,118],valign:'top'},headStyles:{fillColor:[28,38,53],textColor:[230,206,150]},alternateRowStyles:{fillColor:[247,248,250]},rowPageBreak:'avoid',didParseCell:cell=>{if(cell.section!=='body')return;const palette=rowPalette(data.items[cell.row.index]);if(palette){cell.cell.styles.fillColor=rgb(palette.background);cell.cell.styles.textColor=rgb(palette.foreground);}if([1,13].includes(cell.column.index)&&!String(data.items[cell.row.index][exportColumns[cell.column.index][0]]??'').trim()){cell.cell.styles.fillColor=[255,228,230];cell.cell.styles.textColor=[159,18,57];}},head:[exportColumns.map(([,label])=>label)],body,columnStyles:Object.fromEntries(widths.map((cellWidth,index)=>[index,{cellWidth,...([6,7].includes(index)?{halign:index===6?'center':'right'}:{})}]))});
 const pages=doc.getNumberOfPages(),width=doc.internal.pageSize.getWidth(),height=doc.internal.pageSize.getHeight();
 for(let page=1;page<=pages;page++){
  doc.setPage(page);
  const left=logo?62:10;
  drawCompanyPdfLogo(doc,logo,10,7,45,22);
  doc.setFontSize(13);doc.setTextColor(28,38,53);
  const title=i18nText`${exportCompanyName(data.company)} · Şehir Dışı Sevkiyat Listesi`;
  doc.setFontSize(Math.min(13,13*(width-left-10)/Math.max(1,doc.getTextWidth(title))));
  doc.text(title,left,12);
  doc.setFontSize(10);doc.text(shipmentExportTitle(data),left,19);
  doc.setFontSize(8);doc.text(doc.splitTextToSize(summaryText(shipmentSummary(data))+' · '+scopeDescription(data),width-left-10).slice(0,2),left,26);
  doc.text(i18nText`${data.total} kayıt · OmniGoGlobal`,10,height-7);doc.text(`${page} / ${pages}`,width-10,height-7,{align:'right'});
 }
 return doc.output('blob');
}
export async function downloadShipments(data,type,isCurrent,destination=null,selectedFilename=null,onFileReady){
 if(!data.items.length)throw new Error(i18nT('Dışa aktarılacak kayıt bulunamadı.'));
 const filename=selectedFilename||shipmentExportFilename(data,type);
 const blob=type==='xlsx'?await createShipmentWorkbook(data):await createShipmentPdf(data);
 if(isCurrent())onFileReady?.({blob,filename});
 return downloadStatus(await saveReportFile(blob,filename,isCurrent,globalThis,destination,{browserDownload:true}));
}
