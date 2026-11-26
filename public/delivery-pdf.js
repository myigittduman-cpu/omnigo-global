import {t as i18nT,text as i18nText} from './i18n.js';
import {deliverySummary,summaryText} from './export-summary.js';
import {deliveryFields,deliveryProducts} from './delivery-fields.js';
import {deliveryPackageLabels,printPackageBarcodes} from './delivery-package-barcodes.js';
import {exportCompanyName} from './company-export-brand.js';
import {deliveryPdfLayout} from './delivery-pdf-layout.js';

export function createDeliveryPdf(snapshot,logos){
 if(!snapshot.orders?.length)throw new Error(i18nT('Evrakta sipariş bulunamadı.'));
 const company=exportCompanyName(snapshot.company||snapshot.sender);
 const {doc,table,details,products,heading}=deliveryPdfLayout({title:i18nText`Teslimat Evrakları · ${snapshot.city}`,company});
 const headers=new Map(),summary=summaryText(deliverySummary(snapshot.orders));
 snapshot.orders.forEach((order,index)=>{
  if(index)doc.addPage();
  const first=doc.getNumberOfPages();
  printPackageBarcodes(doc,table,deliveryPackageLabels(order),35);
  details(doc.lastAutoTable.finalY+2,deliveryFields(order,snapshot.sender),[2,6]);
  products(doc.lastAutoTable.finalY+5,[i18nT('Ürün Kodu'),i18nT('Ürün Adı'),i18nT('Adet')],
   deliveryProducts(order).map(product=>[product.sku,product.title,String(product.quantity)]),
   {0:{cellWidth:43},1:{cellWidth:215},2:{cellWidth:19,halign:'center'}});
  for(let page=first;page<=doc.getNumberOfPages();page++)headers.set(page,order);
 });
 for(const [page,order] of headers){
  const marketplaceLogo=logos[order.provider];
  if(!marketplaceLogo)throw new Error('Pazaryeri logosu eksik.');
  heading(page,{logo:logos.sender,marketplaceLogo,subtitle:i18nT('Teslimat Evrakı · ')+(snapshot.city||order.provider),
   summary,footer:i18nText`Sipariş: ${order.orderNumber}`});
 }
 return doc.output('blob');
}
