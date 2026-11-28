import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {Workbook} from './vendor/report-xlsx-070.js';
import {exportText} from './report-export-data.js';
import {summaryText} from './export-summary.js';
import {receivedExportColors,receivedExportRow} from './payment-export-style.js';
import {configureWorkbook,configureTableSheet,finishTableSheet,tableHeading,styleHeader,styleBody,calendarWorkbookDate,turkeyWorkbookDate,workbookFormats,addWorkbookTable,workbookBlob} from './workbook-theme.js';

function columnWidth(label){
 if(label===i18nT('Açıklama'))return 95;
 if(label===i18nT('Ürün'))return 58;
 if(label===i18nT('Gösterge'))return 46;
 if(label.includes(i18nT('Kaynak')))return 42;
 if(label===i18nT('Bilgi'))return 33;
 if(label===i18nT('Durum'))return 30;
 if(label===i18nT('Ürün kodu'))return 28;
 return Math.min(30,Math.max(20,label.length+5));
}
function reportCell(value,label,table,row){
 if(value==null||typeof value==='number'||value instanceof Date)return value;
 if([i18nT('Gün'),i18nT('Planlanan tarih'),i18nT('Hesaba geçiş')].includes(label))return calendarWorkbookDate(value);
 if(table.name===i18nT('Veri kapsamı')&&label===i18nT('Açıklama')&&(row[0]===i18nT('Oluşturulma')||row[0].endsWith(i18nT('son aktarım'))))return turkeyWorkbookDate(value)||exportText(value);
 return exportText(value);
}
export async function createWorkbook(report,tables,logos=[]){
 const workbook=new Workbook();configureWorkbook(workbook,report.generatedAt,report.company.name);
 for(const table of tables){
  const sheet=workbook.addWorksheet(table.name);
  const widths=table.head.map(columnWidth);
  configureTableSheet(sheet,widths,{company:report.company.name,paperSize:table.head.length>7?8:9});
  const countNote=summaryText({orderCount:report.totals?.orders??'—',productCount:report.products?.length?report.products.reduce((sum,item)=>sum+(Number(item.quantity)||0),0):'—'});
  tableHeading(sheet,{title:table.name,context:exportText(report.company.name)+' · '+report.from+' – '+report.to,note:countNote+i18nT(' · Karşılaştırma: ')+report.previousFrom+' – '+report.previousTo,columns:widths.length,brand:report.company.name,logo:logos[0]});
  sheet.getRow(7).values=table.head;styleHeader(sheet.getRow(7),widths.length);
  for(const [index,values] of table.rows.entries()){
   const row=sheet.addRow(values.map((value,column)=>reportCell(value,table.head[column],table,values)));
   const money=values[0]===i18nT('Sipariş sayısı')?[]:table.money.map(column=>column+1);
   const integers=table.head.map((label,column)=>table.integers?.includes(column)||[i18nT('Sipariş'),i18nT('Brüt adet'),i18nT('Net adet')].includes(label)||values[0]===i18nT('Sipariş sayısı')?column+1:0).filter(Boolean);
   styleBody(row,widths,{money,integers,texts:table.head.map((label,column)=>label===i18nT('Ürün kodu')?column+1:0).filter(Boolean)});
   row.eachCell((cell,column)=>{
    if(cell.value instanceof Date)cell.numFmt=table.name===i18nT('Veri kapsamı')||table.dateTimes?.includes(column-1)?workbookFormats.dateTime:workbookFormats.date;
    if(table.percentages?.includes(column-1))cell.numFmt='0.00%';
    if(table.cellNotes?.[index]?.[column-1])cell.note=exportText(table.cellNotes[index][column-1]);
   });
   if(receivedExportRow(table,index))row.eachCell({includeEmpty:true},cell=>{
    cell.fill={type:'pattern',pattern:'solid',fgColor:{argb:receivedExportColors.fillArgb}};
    cell.font={...cell.font,color:{argb:receivedExportColors.textArgb}};
   });
  }
  addWorkbookTable(sheet,widths.length);
  finishTableSheet(sheet,widths);
 }
 return workbookBlob(workbook);
}
