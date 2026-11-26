import {t as i18nT,text as i18nText,html as i18nHtml,labelRows} from './i18n.js';
import {shipmentSummary,summaryText} from './export-summary.js';
import {Workbook} from './vendor/report-xlsx-070.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {configureWorkbook,configureTableSheet,finishTableSheet,tableHeading,styleHeader,styleBody,workbookText,turkeyWorkbookDate,workbookBlob,workbookFont,workbookFormats,solidFill} from './workbook-theme.js';
import {companyExportLogo,exportCompanyName} from './company-export-brand.js';

export const shipmentWorkbookColumns=labelRows([
 ['orderedAt','Sipariş tarihi / saati',23],['recipientName','Alıcı adı',27],
 ['phone','Telefon',19],['province','Teslimat ili',18],['district','Teslimat ilçesi',21],
 ['productName','Ürün adı',48],['quantity','Adet',9],['invoiceCents','Fatura tutarı (TL)',22],
 ['deliveryAddress','Teslimat adresi',58],['invoiceName','Fatura adı',28],['invoiceAddress','Fatura adresi',58],
 ['recipientEmail','Alıcı e-posta',36],['customerType','Müşteri tipi',18],['taxNumber','Vergi numarası',20],
 ['taxOffice','Vergi dairesi',25],['customerName','Müşteri adı',27],['provider','Pazaryeri',18],['orderNumber','Sipariş numarası',26],
]);
function cellValue(item,key){
 if(key==='customerName'&&item.customerNameSource==='recipient')return workbookText(i18nText`Sipariş sahibi bildirilmedi; alıcı: ${item[key]||''}`);
 if(key==='orderedAt')return turkeyWorkbookDate(item[key]);
 if(key==='invoiceCents'){const amount=shipmentTableAmountCents(item);return amount===null?null:amount/100;}
 if(key==='quantity')return item[key]??null;
 if(['invoiceAddress','customerType','taxNumber','taxOffice'].includes(key)){const value=shipmentInvoiceFields(item)[key];return workbookText(key==='customerType'?i18nT(value):value);}
 if(['recipientName','orderNumber'].includes(key)&&!String(item[key]??'').trim())return key==='recipientName'?i18nT('Alıcı adı eksik'):i18nT('Sipariş numarası doğrulanamadı');
 return workbookText(item[key]);
}
export async function buildShipmentWorkbook(data,{title,scope}){
 const workbook=new Workbook();configureWorkbook(workbook,new Date(),exportCompanyName(data.company));
 const sheet=workbook.addWorksheet(i18nT('Sevkiyatlar'));
 const widths=shipmentWorkbookColumns.map(([, ,width])=>width);
 // Column defaults also style newly entered rows without an Excel Table or macros.
 const formats=shipmentWorkbookColumns.map(([key])=>key==='invoiceCents'?workbookFormats.money:key==='quantity'?workbookFormats.integer:key==='orderedAt'?workbookFormats.dateTime:workbookFormats.text);
 configureTableSheet(sheet,widths,{form:true,company:exportCompanyName(data.company),formats,rightAligned:[7,8]});
 tableHeading(sheet,{title:i18nT('Şehir Dışı Sevkiyatlar'),context:title,note:i18nText`${summaryText(shipmentSummary(data))} · ${scope} · ${data.items.length} kayıt${data.summary?.unmatchedRows?i18nText` · Sipariş numarası olmayan ${data.summary.unmatchedRows} satır ayrı sayıldı.`:''}`,columns:widths.length,brand:exportCompanyName(data.company),logo:await companyExportLogo(data.company)});
 sheet.getRow(7).values=shipmentWorkbookColumns.map(([,label])=>label);styleHeader(sheet.getRow(7),widths.length);
 for(const item of data.items){
  const row=sheet.addRow(shipmentWorkbookColumns.map(([key])=>cellValue(item,key)));
  styleBody(row,widths,{money:[8],integers:[7],dates:[1],texts:[3,14,18]});
  for(const col of [2,18])if(!String(item[shipmentWorkbookColumns[col-1][0]]??'').trim()){const cell=row.getCell(col);cell.font={...workbookFont(),color:{argb:'FF9F1239'},bold:true};cell.fill=solidFill('FFFFE4E6');}
 }
 finishTableSheet(sheet,widths);
 return workbookBlob(workbook);
}
