import {t,text,currentLocale} from './i18n.js';
import {companyExportLogo,exportCompanyName} from './company-export-brand.js';
import {deliveryMarketplaceLogo} from './delivery-logos.js';
import {deliveryPdfLayout} from './delivery-pdf-layout.js';
import {shipmentSummary,summaryText} from './export-summary.js';
import {shipmentExportColumns} from './shipment-export-fields.js';

const detailGroups=[
 ['recipientName','recipientEmail','phone','deliveryAddress'],
 ['invoiceName','invoiceAddress','customerType','taxNumber','taxOffice'],
 ['orderedAt','orderNumber'],
];
const productKeys=['productName','quantity','invoiceCents'];
const labels=new Map(shipmentExportColumns.map(column=>[column[0],column]));
const label=key=>labels.get(key)[1];

function shipmentSections(items,readValue,fallbackProvider){
 const sections=[];
 for(const row of items){
  const fields=detailGroups.flatMap(group=>group.map(key=>[label(key),readValue(row,key)]));
  const order=String(row.orderNumber??'').trim(),provider=row.provider||fallbackProvider;
  const identity=order&&provider?JSON.stringify([provider,order,fields]):null;
  const amount=readValue(row,'invoiceCents');
  const line=[readValue(row,'productName'),readValue(row,'quantity'),
   amount===null?'—':amount.toLocaleString(currentLocale(),{minimumFractionDigits:2,maximumFractionDigits:2})+' TL'];
  const last=sections.at(-1);
  if(identity&&last?.identity===identity)last.lines.push(line);
  else sections.push({identity,provider,order,fields,lines:[line]});
 }
 return sections;
}

export async function createShipmentDocument(data,{title,scope,readValue}){
 const company=exportCompanyName(data.company);
 const sections=shipmentSections(data.items,readValue,data.provider==='all'?'':data.provider);
 const providers=[...new Set(sections.map(section=>section.provider).filter(Boolean))];
 const browser=typeof Image==='function'&&typeof location!=='undefined';
 const [logo,...marketplaces]=await Promise.all([
  companyExportLogo(data.company),
  ...providers.map(provider=>browser?deliveryMarketplaceLogo(provider).catch(()=>null):null),
 ]);
 const brand=new Map(providers.map((provider,index)=>[provider,marketplaces[index]]));
 const {doc,details,products,heading}=deliveryPdfLayout({title,company});
 const headers=new Map();
 const summary=summaryText(shipmentSummary(data))+' · '+scope;
 const subtitle=t('Şehir Dışı Sevkiyat Listesi')+' · '+title;
 const recordCount=text`${data.total} kayıt · OmniGoGlobal`;
 sections.forEach(({provider,order,fields,lines},index)=>{
  if(index)doc.addPage();
  const first=doc.getNumberOfPages();
  details(35,fields,[detailGroups[0].length,detailGroups[0].length+detailGroups[1].length]);
  products(doc.lastAutoTable.finalY+5,productKeys.map(label),lines,
   {0:{cellWidth:215},1:{cellWidth:19,halign:'center'},2:{cellWidth:43,halign:'right'}});
  const marketplaceLogo=brand.get(provider),headingTitle=!marketplaceLogo&&provider&&data.provider==='all'?subtitle+' · '+provider:subtitle;
  for(let page=first;page<=doc.getNumberOfPages();page++)headers.set(page,{
   marketplaceLogo,subtitle:headingTitle,footer:order?text`Sipariş: ${order}`+' · '+recordCount:recordCount,
  });
 });
 if(!sections.length)headers.set(1,{subtitle,footer:recordCount});
 for(const [page,header] of headers)heading(page,{...header,logo,summary});
 return doc.output('blob');
}
