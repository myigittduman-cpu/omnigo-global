import {Workbook} from './vendor/report-xlsx-070.js';
import {exportCompanyName} from './company-export-brand.js';
import {configureWorkbook,configureTableSheet,finishTableSheet,tableHeading,styleHeader,styleBody,turkeyWorkbookDate,workbookBlob} from './workbook-theme.js';
import {cityProductsColumns} from './city-products-document.js';
import {t} from './i18n.js';

export async function cityProductsWorkbook(model,logo){
 const workbook=new Workbook(),brand=exportCompanyName(model.company),sheet=workbook.addWorksheet(model.title);
 const widths=[21,27,19,56,18,21,25,50,11];
 configureWorkbook(workbook,model.createdAt,brand);
 configureTableSheet(sheet,widths,{company:brand,paperSize:9,rightAligned:[9]});
 tableHeading(sheet,{title:model.title,context:model.city,note:model.summary,columns:widths.length,brand,logo});
 styleHeader(sheet.addRow(cityProductsColumns.map(label=>t(label))),widths.length);
 for(const item of model.rows){
  const row=sheet.addRow([turkeyWorkbookDate(item.orderedAt),item.recipient,item.phone,item.address,item.city,item.district,item.sku,item.name,item.quantity]);
  styleBody(row,widths,{dates:[1],texts:[3,7],integers:[9]});
 }
 const total=sheet.addRow([t('Toplam adet'),'','','','','','','',model.quantity]);styleHeader(total,widths.length);
 finishTableSheet(sheet,widths);
 return workbookBlob(workbook);
}
