import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {deliverySummary,summaryText} from './export-summary.js';
import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {deliveryFields,deliveryProducts} from './delivery-fields.js';
import {deliveryPackageLabels,printPackageBarcodes} from './delivery-package-barcodes.js';
import {drawCompanyPdfLogo,exportCompanyName} from './company-export-brand.js';

export function createDeliveryPdf(snapshot,logos){
 if(!snapshot.orders?.length)throw new Error(i18nT('Evrakta sipariş bulunamadı.'));
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
 doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');
 const company=exportCompanyName(snapshot.company||snapshot.sender);
 doc.setProperties({title:i18nText`Teslimat Evrakları · ${snapshot.city}`,subject:company,creator:'OmniGoGlobal'});
 const headers=new Map(),left=10,right=287;
 const table=options=>autoTable(doc,{margin:{top:35,bottom:14,left,right:10},theme:'plain',styles:{font:'NotoSans',fontStyle:'normal',fontSize:10,cellPadding:{top:1.3,bottom:1.3,left:2,right:2},overflow:'linebreak',textColor:20,lineWidth:0},rowPageBreak:'avoid',...options});
 snapshot.orders.forEach((order,index)=>{
  if(index)doc.addPage();const first=doc.getNumberOfPages();
  printPackageBarcodes(doc,table,deliveryPackageLabels(order),35);
  table({startY:doc.lastAutoTable.finalY+2,body:deliveryFields(order,snapshot.sender).map(([key,value])=>[key,':',value||'—']),columnStyles:{0:{cellWidth:53},1:{cellWidth:5},2:{cellWidth:219}},didDrawCell:data=>{
   if(data.column.index===0&&[2,6].includes(data.row.index)){doc.setDrawColor(90);doc.setLineWidth(.15);doc.line(left,data.cell.y,right,data.cell.y);}
  }});
  table({startY:doc.lastAutoTable.finalY+5,theme:'grid',head:[[i18nT('Ürün Kodu'),i18nT('Ürün Adı'),i18nT('Adet')]],body:deliveryProducts(order).map(product=>[product.sku,product.title,String(product.quantity)]),styles:{font:'NotoSans',fontStyle:'normal',fontSize:10,cellPadding:3,lineWidth:.15,lineColor:[90,90,90],minCellHeight:18},headStyles:{fillColor:[245,245,245],textColor:20,minCellHeight:9,fontStyle:'normal'},columnStyles:{0:{cellWidth:43},1:{cellWidth:215},2:{cellWidth:19,halign:'center'}}});
  for(let page=first;page<=doc.getNumberOfPages();page++)headers.set(page,order);
 });
 for(let page=1;page<=doc.getNumberOfPages();page++){
  doc.setPage(page);const order=headers.get(page);if(!logos[order.provider])throw new Error('Pazaryeri logosu eksik.');
  drawCompanyPdfLogo(doc,logos.sender,left,5,45,18);
  drawCompanyPdfLogo(doc,logos[order.provider],right-35,6,35,18);
  const textX=logos.sender?left+52:left;
  doc.setFont('NotoSans');doc.setFontSize(14);doc.setTextColor(28,38,53);
  doc.setFontSize(Math.min(14,14*(right-48-textX)/Math.max(1,doc.getTextWidth(company))));
  doc.text(company,textX,11);
  doc.setFontSize(11);doc.text(i18nT('Teslimat Evrakı · ')+(snapshot.city||order.provider),textX,18);
  doc.setFontSize(8);doc.setTextColor(60);doc.text(summaryText(deliverySummary(snapshot.orders)),textX,25);
  doc.setDrawColor(199,170,114);doc.setLineWidth(.3);doc.line(left,32,right,32);
  doc.setFont('NotoSans');doc.setFontSize(8);doc.setTextColor(95);doc.text(i18nText`Sipariş: ${order.orderNumber}`,left,202);doc.text(`${page} / ${doc.getNumberOfPages()}`,right,202,{align:'right'});
 }
 return doc.output('blob');
}
