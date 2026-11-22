import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {deliverySummary,summaryText} from './export-summary.js';
import {Workbook} from './vendor/report-xlsx-070.js';
import {deliveryFields,deliveryProducts} from './delivery-fields.js';
import {deliveryPackageLabels,missingPackageBarcode,workbookBarcodeImage} from './delivery-package-barcodes.js';
import {exportCompanyName} from './company-export-brand.js';
import {configureWorkbook,configureSheet,workbookColors,workbookFont,workbookBorders,solidFill,mergedText,styleHeader,workbookBrandHeading,workbookBlob} from './workbook-theme.js';

export async function createDeliveryWorkbook(snapshot,logos){
 if(!snapshot.orders?.length)throw new Error(i18nT('Evrakta sipariş bulunamadı.'));
 const company=exportCompanyName(snapshot.company||snapshot.sender);
 const workbook=new Workbook();configureWorkbook(workbook,new Date(),company);
 for(const provider of new Set(snapshot.orders.map(order=>order.provider))){
  const sheet=workbook.addWorksheet(provider);configureSheet(sheet,{form:true,paperSize:9,company});
  sheet.columns=[{width:29},{width:8},{width:19},{width:30},{width:30},{width:13}];
  const summary=summaryText(deliverySummary(snapshot.orders.filter(order=>order.provider===provider)));
  let start=2;mergedText(sheet,1,1,6,summary,{size:12,bold:true,height:28});
  for(const order of snapshot.orders.filter(order=>order.provider===provider)){
   workbookBrandHeading(sheet,{row:start,columns:6,brand:company,title:i18nT('Teslimat Evrakı · ')+(snapshot.city||provider),logo:logos.sender,partnerLogo:logos[provider]});
   start++;
   for(const label of deliveryPackageLabels(order)){
    start++;
    mergedText(sheet,start,1,2,label.caption,{size:10,bold:true,height:label.value?65:27});
    if(label.value){
     const image=workbookBarcodeImage(label);
     const id=workbook.addImage({base64:image.base64,extension:'png'});
     sheet.addImage(id,{tl:{col:2,row:start-1+.06},ext:{width:image.width,height:image.height},editAs:'oneCell'});
    }else mergedText(sheet,start,3,6,missingPackageBarcode(),{size:10,height:27});
   }
   for(const [index,[label,value]] of deliveryFields(order,snapshot.sender).entries()){
    const row=sheet.getRow(++start);sheet.mergeCells(start,1,start,2);sheet.mergeCells(start,3,start,6);
    row.getCell(1).value=label;row.getCell(3).value=value||'—';row.getCell(3).numFmt='@';
    const lines=Math.max(1,...String(value||'').split(/\r?\n/).map(line=>Math.ceil(line.length/86)));
    row.height=Math.max(27,lines*16+10);
    for(const column of [1,3]){
     const cell=row.getCell(column);cell.font=workbookFont({bold:column===1});
     cell.fill=solidFill(index%2?workbookColors.stripe:workbookColors.white);
     cell.alignment={wrapText:true,vertical:'middle',horizontal:'left'};
     cell.border=workbookBorders();
    }
   }
   start++;sheet.mergeCells(start,1,start,2);sheet.mergeCells(start,3,start,5);
   const header=sheet.getRow(start);styleHeader(header,6);
   for(const [column,label] of [[1,i18nT('Ürün Kodu')],[3,i18nT('Ürün Adı')],[6,i18nT('Adet')]])header.getCell(column).value=label;
   for(const [index,product] of deliveryProducts(order).entries()){
    const row=sheet.getRow(++start);sheet.mergeCells(start,1,start,2);sheet.mergeCells(start,3,start,5);
    row.getCell(1).value=product.sku;row.getCell(1).numFmt='@';row.getCell(3).value=product.title;row.getCell(6).value=product.quantity;
    row.height=Math.max(38,Math.ceil(product.title.length/78)*16+12);
    for(const column of [1,3,6]){
     const cell=row.getCell(column);cell.font=workbookFont({bold:column===6});cell.fill=solidFill(index%2?workbookColors.stripe:workbookColors.white);
     cell.alignment={wrapText:true,vertical:'middle',horizontal:column===6?'center':'left'};
     cell.border=workbookBorders();
    }
   }
   sheet.getRow(start).addPageBreak();start+=2;
  }
  sheet.pageSetup.printArea='A1:F'+(start-2);
 }
 return workbookBlob(workbook);
}
