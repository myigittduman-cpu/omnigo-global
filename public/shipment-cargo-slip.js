import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {t} from './i18n.js';
import {companyExportLogo,drawCompanyPdfLogo,exportCompanyName} from './company-export-brand.js';
import {deliveryMarketplaceLogo} from './delivery-logos.js';
import {deliveryPackageLabels,missingPackageBarcode} from './delivery-package-barcodes.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';

const clean=value=>String(value??'').trim();
const values=list=>[...new Set(list.map(clean).filter(Boolean))].join('\n');
const field=(label,value)=>clean(value)?[t(label),clean(value)]:null;

function detailRows(leftTitle,rightTitle,leftFields,rightFields){
 const left=leftFields.filter(Boolean),right=rightFields.filter(Boolean);
 const header=title=>({content:t(title),colSpan:2,styles:{fillColor:[242,242,242]}});
 return [[header(leftTitle),header(rightTitle)],...Array.from({length:Math.max(left.length,right.length)},(_,index)=>[
  ...(left[index]??['','']),...(right[index]??['','']),
 ])];
}

function orderLabels(rows){
 const labels=new Map(),owners=new Map();
 for(const {shippingLabels=[]} of rows)for(const label of shippingLabels){
  const key=label.packageId?'id:'+label.packageId:'code:'+label.value;
  const previous=labels.get(key);
  if(!previous)labels.set(key,{...label});
  else if(previous.value!==label.value)previous.value=null;
  if(label.value&&label.packageId){
   if(!owners.has(label.value))owners.set(label.value,new Set());
   owners.get(label.value).add(key);
  }
 }
 for(const keys of owners.values())if(keys.size>1){
  for(const key of keys)labels.get(key).value=null;
 }
 const list=[...labels.values()];
 return deliveryPackageLabels({shippingLabels:list}).map((label,index)=>({...label,carrier:list[index]?.carrier}));
}

function printPackages(doc,table,rows){
 const labels=orderLabels(rows);
 table({startY:46,theme:'grid',body:labels.map(label=>[
  label.value?'':missingPackageBarcode(),[label.carrier,label.caption].filter(Boolean).join('\n'),
 ]),columnStyles:{0:{cellWidth:115},1:{cellWidth:67}},
  didParseCell:({row,cell})=>{if(labels[row.index].value)cell.styles.minCellHeight=24;},
  didDrawCell:({row,column,cell})=>{
   const label=labels[row.index];
   if(column.index!==0||!label.value)return;
   const moduleWidth=Math.min(.38,(cell.width-8)/label.modules);
   const left=cell.x+(cell.width-label.modules*moduleWidth)/2,top=cell.y+4;
   doc.setFillColor(0);
   for(const bar of label.bars)doc.rect(left+bar.start*moduleWidth,top,bar.length*moduleWidth,13,'F');
   doc.setFontSize(9);doc.setTextColor(20);
   doc.text(label.value,cell.x+cell.width/2,top+17,{align:'center'});
  },
 });
}

export function shipmentCargoSlipPdf(rows,company,logos={}){
 const first=rows[0],provider=first.provider;
 const doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
 doc.addFileToVFS('NotoSans.ttf',font);
 doc.addFont('NotoSans.ttf','NotoSans','normal');
 doc.setFont('NotoSans');
 doc.setProperties({title:t('Kargo fişi'),subject:exportCompanyName(company)});
 const table=options=>autoTable(doc,{
  margin:{left:14,right:14,top:18,bottom:13},theme:'plain',rowPageBreak:'avoid',
  styles:{font:'NotoSans',fontSize:10,cellPadding:3,textColor:24,overflow:'linebreak',lineColor:[130,130,130]},
  headStyles:{fontStyle:'normal',fillColor:[242,242,242],textColor:24},...options,
 });
 // The logo endpoint already supplies the complete gold-framed store badge.
 if(logos.company)drawCompanyPdfLogo(doc,logos.company,70,5,70,28);
 else{
  doc.setDrawColor(178,145,80);doc.setLineWidth(.55);
  doc.ellipse(105,21,39,11,'S');
  doc.setFontSize(11);doc.setTextColor(24);
  doc.text(exportCompanyName(company),105,22,{align:'center',maxWidth:64});
 }
 if(logos.marketplace)drawCompanyPdfLogo(doc,logos.marketplace,90,34,30,10);
 else{
  doc.setFontSize(11);doc.setTextColor(24);
  doc.text(provider,105,41,{align:'center'});
 }
 printPackages(doc,table,rows);
 const billing=rows.map(shipmentInvoiceFields);
 const locations=rows.map(row=>[row.district,row.province].map(clean).filter(Boolean).join(' / '));
 const blocks=[
  ...detailRows('Teslimat bilgileri','Fatura bilgileri',[
   field('Teslim alacak kişi',values(rows.map(row=>row.recipientName))),
   field('İlçe / İl',values(locations)),
   field('Teslimat adresi',values(rows.map(row=>row.deliveryAddress))),
  ],[
   field('Müşteri adı',values(rows.map(row=>row.invoiceName))),
   field('Fatura adresi',values(billing.map(item=>item.invoiceAddress))),
   field('Vergi dairesi',values(billing.map(item=>item.taxOffice))),
   field('Vergi numarası',values(billing.map(item=>item.taxNumber))),
  ]),
  ...detailRows('Satıcı bilgileri','Sipariş bilgileri',
   [field('Satıcı adı',company?.title||exportCompanyName(company))],
   [field('Sipariş numarası',values(rows.map(row=>row.orderNumber)))]),
 ];
 table({startY:doc.lastAutoTable.finalY+4,theme:'grid',body:blocks,
  columnStyles:{0:{cellWidth:28},1:{cellWidth:63},2:{cellWidth:28},3:{cellWidth:63}},
 });
 table({startY:doc.lastAutoTable.finalY+5,theme:'grid',head:[[t('SKU'),t('Ürünler'),t('Adet')]],
  body:rows.map(row=>[clean(row.productCode)||'—',clean(row.productName)||'—',String(row.quantity??'—')]),
  columnStyles:{0:{cellWidth:35},1:{cellWidth:130},2:{cellWidth:17,halign:'right'}},
 });
 return doc.output('blob');
}

export async function createShipmentCargoSlip(rows,company){
 const [shop,marketplace]=await Promise.all([
  companyExportLogo(company).catch(()=>null),
  deliveryMarketplaceLogo(rows[0].provider).catch(()=>null),
 ]);
 const number=clean(rows[0].orderNumber).replace(/[^\p{L}\p{N}_.-]/gu,'-').slice(0,65);
 return {blob:shipmentCargoSlipPdf(rows,company,{company:shop,marketplace}),filename:'Kargo-fisi'+(number?'-'+number:'')+'.pdf'};
}

