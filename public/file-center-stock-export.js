import {Workbook} from './vendor/report-xlsx-070.js';
import {configureWorkbook,configureTableSheet,finishTableSheet,styleHeader,styleBody,tableHeading,workbookBlob} from './workbook-theme.js';
import {priceLabel,statusLabel} from './file-center-view.js';
import {companyExportLogo,exportCompanyName} from './company-export-brand.js';
import {fileCenterStockFilename} from './file-center-export-name.js';
import {t,text} from './i18n.js';

export async function createFileCenterStockFile(data,company,createdAt=new Date()){
 const brand=exportCompanyName(company),title=t('Pazaryeri Ürünleri');
 const workbook=new Workbook(),sheet=workbook.addWorksheet(title);
 configureWorkbook(workbook,createdAt,brand);
 const providers=data.providers.map(provider=>provider.name);
 const columns=[42,22,...providers.flatMap(()=>[27,20,24])];
 const labels=['Ürün','Stok kodu',...providers.flatMap(name=>[name+' Durum',name+' Satış fiyatı',name+' Fiyat kaynağı'])];
 const money=providers.map((_,column)=>4+column*3);
 configureTableSheet(sheet,columns,{company:brand,rightAligned:money});
 tableHeading(sheet,{title,context:text`Sayfa ${data.page+1}`,note:text`${data.items.length} ürün`,columns:columns.length,brand,logo:await companyExportLogo(company)});
 styleHeader(sheet.addRow(labels),columns.length);
 for(const item of data.items){
  const fields=providers.flatMap(name=>{
   const market=item.markets[name];
   return [statusLabel(market?.status),market?.priceCents==null?null:market.priceCents/100,market?.priceSource?priceLabel(market.priceSource):''];
  });
  styleBody(sheet.addRow([item.name,item.sku,...fields]),columns,{texts:[2],money});
 }
 finishTableSheet(sheet,columns);
 return {blob:await workbookBlob(workbook),filename:fileCenterStockFilename(data,company,createdAt)};
}
