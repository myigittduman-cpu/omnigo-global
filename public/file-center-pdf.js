import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {exportCompanyName,companyExportLogo} from './company-export-brand.js';
import {drawReportTableHeading} from './report-table-heading.js';
import {fileCenterPdfFilename} from './file-center-export-name.js';
import {marketplaceBrand} from './marketplace-branding.js';
import {t,text} from './i18n.js';
import {now,money} from './ui.js';
import {statusLabel,priceLabel} from './file-center-view.js';

const page={width:297,padding:14,bodyTop:34};
const colors={panel:[29,44,63],gold:[242,234,211],line:[221,227,233],text:[25,39,57],alternate:[246,248,250]};

async function loadImage(path){
 if(!path?.startsWith('/')||path.startsWith('//'))return null;
 const source=new URL(path,location.origin);
 if(source.origin!==location.origin)return null;
 const image=new Image();
 let timer;
 try{
  await new Promise((resolve,reject)=>{
   image.onload=resolve;
   image.onerror=reject;
   timer=setTimeout(reject,5000);
   image.src=source.href;
  });
  if(!image.naturalWidth||!image.naturalHeight)return null;
  const scale=Math.min(1,192/Math.max(image.naturalWidth,image.naturalHeight));
  const canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));
  canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
  canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
  return {data:canvas.toDataURL('image/png'),width:canvas.width,height:canvas.height};
 }catch{return null;}
 finally{clearTimeout(timer);image.onload=null;image.onerror=null;}
}

function drawImage(doc,image,x,y,width,height){
 if(!image)return;
 const scale=Math.min(width/image.width,height/image.height);
 const w=image.width*scale,h=image.height*scale;
 doc.addImage(image.data,'PNG',x+(width-w)/2,y+(height-h)/2,w,h);
}

function marketLines(market){
 const lines=[statusLabel(market?.status)];
 if(market?.priceCents==null)return [...lines,t('Fiyat kaydı yok')];
 return [...lines,money(market.priceCents),market.priceSource==='catalogue'?t('Katalog fiyatı'):priceLabel(market.priceSource)];
}

function catalogDocument(height){
 const doc=new jsPDF({orientation:height>page.width?'portrait':'landscape',unit:'mm',
  format:[page.width,height],compress:true,putOnlyUsedFonts:true});
 doc.addFileToVFS('NotoSans.ttf',font);
 doc.addFont('NotoSans.ttf','NotoSans','normal');
 doc.setFont('NotoSans');
 return doc;
}

export async function createFileCenterPdf(data,company,date=now()){
 const brands=data.providers.map(provider=>{
  const brand=marketplaceBrand(provider.name);
  return brand?'/assets/'+brand.file:'';
 });
 const [logo,logos,photos]=await Promise.all([
  companyExportLogo(company),Promise.all(brands.map(loadImage)),
  Promise.all(data.items.map(item=>loadImage(item.imageUrl))),
 ]);
 const heading=t('Pazaryeri Ürünleri');
 const width=page.width-2*page.padding,productWidth=data.providers.length?Math.min(70,width*.3):width;
 const marketWidth=data.providers.length?(width-productWidth)/data.providers.length:0;
 const columns={0:{cellWidth:productWidth}};
 data.providers.forEach((_,index)=>{columns[index+1]={cellWidth:marketWidth};});
 const table={
  startY:page.bodyTop,margin:{top:page.bodyTop,bottom:page.padding,left:page.padding,right:page.padding},
  theme:'grid',tableWidth:width,rowPageBreak:'avoid',columnStyles:columns,
  head:[[t('Ürün'),...data.providers.map(provider=>provider.name)]],
  body:data.items.map(item=>[[item.name,item.sku||item.barcode].filter(Boolean).join('\n'),
   ...data.providers.map(provider=>marketLines(item.markets[provider.name]).join('\n'))]),
  styles:{font:'NotoSans',fontStyle:'normal',fontSize:9,overflow:'linebreak',valign:'middle',minCellHeight:17,
   cellPadding:2.5,fillColor:[255,255,255],textColor:colors.text,lineColor:colors.line,lineWidth:.17},
  headStyles:{fontStyle:'normal',fillColor:colors.panel,textColor:colors.gold,minCellHeight:15},
  alternateRowStyles:{fillColor:colors.alternate},
  didParseCell:({cell,row,column})=>{
   const image=row.section==='head'?logos[column.index-1]:column.index===0&&photos[row.index];
   if(!image)return;
   if(row.section==='head'){
    cell.styles.halign='center';
    cell.styles.cellPadding={top:10,bottom:2,left:1,right:1};
   }else cell.styles.cellPadding={top:2.5,bottom:2.5,left:17,right:2.5};
  },
 };
 // Match the viewer's page to the actual table height, including long product names.
 const measuring=catalogDocument(500);
 autoTable(measuring,table);
 const height=measuring.getNumberOfPages()===1?Math.ceil(measuring.lastAutoTable.finalY+page.padding):210;
 const doc=catalogDocument(height);
 doc.setProperties({title:`${exportCompanyName(company)} · ${heading}`,subject:heading,creator:'Omnigo Global'});
 autoTable(doc,{
  ...table,
  willDrawPage:()=>drawReportTableHeading(doc,{
   margin:page.padding,height:page.bodyTop,company:exportCompanyName(company),logo,title:heading,
   context:text`${data.items.length} ürün · ${date.split('-').reverse().join('.')}`,
  }),
  didDrawCell:({cell,row,column})=>{
   const image=row.section==='head'?logos[column.index-1]:column.index===0&&photos[row.index];
   if(!image)return;
   const height=row.section==='head'?7.5:12;
   const x=row.section==='head'?cell.x+(cell.width-10.5)/2:cell.x+2;
   const y=row.section==='head'?cell.y+1.5:cell.y+(cell.height-height)/2;
   doc.setFillColor(255,255,255);
   doc.roundedRect(x,y,10.5,height,1,1,'F');
   drawImage(doc,image,x+.7,y+.7,9.1,height-1.4);
  },
 });
 return {blob:doc.output('blob'),filename:fileCenterPdfFilename(company,date)};
}
