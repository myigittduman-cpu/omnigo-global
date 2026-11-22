import {companyExportLogo,drawCompanyPdfLogo} from './company-export-brand.js';
import {t,text} from './i18n.js';
import {marketplaceBrand} from './marketplace-branding.js';
import {money} from './ui.js';
import {
 upcomingMarketplaceEntries,upcomingPaymentDate,upcomingPaymentExpectedCents,
 upcomingPaymentFilename,upcomingPaymentTitle,
} from './upcoming-payment-model.js';
import {jsPDF} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';

const palette={
 navy:[16,28,44],panel:[31,39,53],total:[43,48,58],
 gold:[199,170,114],softGold:[237,218,176],line:[101,92,76],
 text:[241,242,245],muted:[181,189,201],white:[255,255,255],
};
const page={width:210,height:297,margin:14,top:54,left:12,right:198,bottom:276};
const columns={brand:20,date:91,amount:190};
const paymentHeight=12,totalHeight=17;

function trimLogoMargins(canvas){
 const {width,height}=canvas;
 const {data}=canvas.getContext('2d').getImageData(0,0,width,height);
 const light=(index)=>data[index+3]<24||
  (data[index]>=245&&data[index+1]>=245&&data[index+2]>=245);
 const whiteMargin=[0,(width-1)*4,width*(height-1)*4,(width*height-1)*4].every(light);
 let x1=width,y1=height,x2=-1,y2=-1;
 for(let y=0;y<height;y++){
  for(let x=0;x<width;x++){
   const index=(y*width+x)*4;
   if(data[index+3]<24||(whiteMargin&&light(index)))continue;
   x1=Math.min(x1,x);y1=Math.min(y1,y);
   x2=Math.max(x2,x);y2=Math.max(y2,y);
  }
 }
 if(x2<x1||y2<y1)return canvas;
 const cropped=document.createElement('canvas');
 cropped.width=x2-x1+1;cropped.height=y2-y1+1;
 cropped.getContext('2d').drawImage(canvas,x1,y1,cropped.width,cropped.height,0,0,cropped.width,cropped.height);
 return cropped;
}

const brandFrame={width:43,height:17.2};

async function loadLogo(source,{trim=false}={}){
 if(!source)return null;
 const image=new Image();let timer;
 try{
  await new Promise((resolve,reject)=>{
   image.onload=resolve;image.onerror=reject;
   timer=setTimeout(reject,6000);
   image.src=source;
  });
  if(!image.naturalWidth||!image.naturalHeight)return null;
  const scale=Math.min(1,360/Math.max(image.naturalWidth,image.naturalHeight));
  const canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));
  canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
  canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);
  const artwork=trim?trimLogoMargins(canvas):canvas;
  return artwork?{data:artwork.toDataURL('image/png'),width:artwork.width,height:artwork.height}:null;
 }catch{return null;}
 finally{clearTimeout(timer);image.onload=null;image.onerror=null;}
}

function fitLogo(doc,logo,x,y,width,height){
 if(!logo)return;
 const scale=Math.min(width/logo.width,height/logo.height);
 const w=logo.width*scale,h=logo.height*scale;
 doc.addImage(logo.data,'PNG',x+(width-w)/2,y+(height-h)/2,w,h);
}

function loadMarketLogo(name){
 const file=marketplaceBrand(name)?.file;
 return file?loadLogo('/assets/'+file,{trim:true}):null;
}

function drawArrow(doc,start,end,y){
 if(end-start<5)return;
 doc.saveGraphicsState();
 doc.setDrawColor(...palette.gold);
 doc.setLineCap('round');doc.setLineJoin('round');
 doc.setLineWidth(.28);doc.line(start,y,end-.15,y);
 doc.setLineWidth(.4);doc.lines([[2.4,1.65],[-2.4,1.65]],end-2.4,y-1.65);
 doc.restoreGraphicsState();
}

function drawClock(doc,x,y){
 doc.setDrawColor(...palette.gold);doc.setLineWidth(.42);
 doc.circle(x,y,3.4,'S');doc.line(x,y-1.8,x,y);doc.line(x,y,x+1.5,y+.8);
}

function drawHeader(doc,heading,companyLogo,{today,period,selectedMonths=[]},height){
 doc.setFillColor(...palette.navy);doc.rect(0,0,page.width,height,'F');
 drawCompanyPdfLogo(doc,companyLogo,columns.brand,20,brandFrame.width,brandFrame.height);
 const headingX=companyLogo?71:columns.brand;
 const headingWidth=columns.amount-headingX;
 doc.setTextColor(...palette.gold);doc.setFontSize(19.5);
 doc.setFontSize(Math.min(19.5,19.5*headingWidth/Math.max(1,doc.getTextWidth(heading))));
 doc.text(heading,headingX,27.5);
 if(period){
  const dateFields=[
   {x:headingX,label:t('Başlangıç tarihi'),date:period.start},
   {x:headingX+48,label:t('Bitiş tarihi'),date:period.end},
  ];
  for(const {x,label,date} of dateFields){
   doc.setFontSize(8.5);doc.setTextColor(...palette.muted);
   doc.text(label,x,33.25);
   doc.setFontSize(10.5);doc.setTextColor(...palette.text);
   doc.text(upcomingPaymentDate(date),x,38.25);
  }
  if(selectedMonths.length>1){
   doc.setFontSize(8.5);doc.setTextColor(...palette.softGold);
   doc.text(text`${selectedMonths.length} seçili ay`,columns.amount,38.25,{align:'right'});
  }
 }else if(today){
  doc.setFontSize(10);doc.setTextColor(...palette.muted);
  doc.text(text`Rapor tarihi: ${upcomingPaymentDate(today)}`,headingX,34.5);
 }
 doc.setFillColor(...palette.panel);doc.rect(page.left,42,page.right-page.left,12,'F');
 doc.setDrawColor(...palette.line);doc.setLineWidth(.25);
 doc.line(page.left,42,page.right,42);
 doc.setTextColor(...palette.softGold);doc.setFontSize(10);
 doc.text(t('Pazaryeri'),columns.brand,49.5);
 doc.text(t('Ödeme tarihi'),columns.date,49.5);
 doc.text(t('Tutar'),columns.amount,49.5,{align:'right'});
}

function drawFrame(doc,bottom){
 doc.setDrawColor(...palette.gold);doc.setLineWidth(.28);
 doc.rect(page.left,page.margin,page.right-page.left,bottom-page.margin);
}

function paymentGroupHeight(count){
 return count*paymentHeight+4;
}

function drawMarketplace(doc,market,entries,logo,y,amountWidth){
 const height=paymentGroupHeight(entries.length);
 const startY=y+(height-entries.length*paymentHeight)/2;
 const firstLineY=startY+paymentHeight/2;
 doc.setDrawColor(...palette.line);doc.setLineWidth(.24);
 doc.line(page.left,y,page.right,y);
 if(logo){
  doc.setFillColor(...palette.white);
  doc.roundedRect(columns.brand,firstLineY-5.3,16,10.6,1.4,1.4,'F');
  fitLogo(doc,logo,columns.brand+1,firstLineY-4.3,14,8.6);
 }
 doc.setFontSize(11.5);doc.setTextColor(...palette.softGold);
 const nameX=columns.brand+(logo?20:0);
 const nameWidth=columns.date-nameX-19;
 doc.setFontSize(Math.min(11.5,11.5*nameWidth/Math.max(1,doc.getTextWidth(market))));
 doc.text(market,nameX,firstLineY+1.55);
 drawArrow(doc,nameX+doc.getTextWidth(market)+5,columns.date-5,firstLineY);
 for(const [index,item] of entries.entries()){
  const rowY=startY+index*paymentHeight;
  if(index){
   doc.setDrawColor(...palette.line);doc.setLineWidth(.15);
   doc.line(columns.date,rowY,columns.amount,rowY);
  }
  const center=rowY+paymentHeight/2;
  const day=upcomingPaymentDate(item.date);
  doc.setTextColor(...palette.text);doc.setFontSize(11.5);
  doc.text(day,columns.date,center+1.55);
  const arrowStart=columns.date+doc.getTextWidth(day)+5;
  doc.setTextColor(...palette.softGold);doc.setFontSize(11.5);
  const amount=money(item.amountCents);
  const arrowEnd=columns.amount-amountWidth-5;
  drawArrow(doc,arrowStart,arrowEnd,center);
  doc.text(amount,columns.amount,center+1.55,{align:'right'});
 }
 return height;
}

function drawExpectedTotal(doc,amount,y){
 const midpoint=y+totalHeight/2;
 doc.setFillColor(...palette.total);doc.rect(page.left,y,page.right-page.left,totalHeight,'F');
 doc.setDrawColor(...palette.line);doc.setLineWidth(.25);
 doc.line(page.left,y,page.right,y);
 drawClock(doc,page.left+10,midpoint);
 doc.setTextColor(...palette.softGold);doc.setFontSize(13);
 doc.text(t('Beklenen tahmini hakediş'),page.left+19,midpoint+1.7);
 const arrowStart=page.left+24+doc.getTextWidth(t('Beklenen tahmini hakediş'));
 doc.setFontSize(16);
 const value=money(amount);
 drawArrow(doc,arrowStart,columns.amount-doc.getTextWidth(value)-5,midpoint);
 doc.text(value,columns.amount,midpoint+2.2,{align:'right'});
}

function paymentGroups(model){
 // Keep the same marketplace together whenever it fits on one page.
 const perPage=Math.floor((page.bottom-page.top-totalHeight-4)/paymentHeight);
 return model.rows.flatMap(market=>{
  const entries=upcomingMarketplaceEntries(market),groups=[];
  for(let offset=0;offset<entries.length;offset+=perPage){
   groups.push({name:market.name,entries:entries.slice(offset,offset+perPage)});
  }
  return groups;
 });
}

function paymentPages(groups){
 const pages=[];
 let current=[],contentHeight=page.top;
 for(const [index,group] of groups.entries()){
  const height=paymentGroupHeight(group.entries.length);
  const last=index===groups.length-1;
  if(contentHeight+height+(last?totalHeight:0)>page.bottom){
   pages.push({groups:current,contentHeight,last:false});
   current=[];
   contentHeight=page.top;
  }
  current.push(group);
  contentHeight+=height;
 }
 pages.push({groups:current,contentHeight:contentHeight+totalHeight,last:true});
 return pages;
}

export function renderUpcomingPaymentPdf(model,company,{marketLogos=new Map(),companyLogo=null,fitToContent=true}={}){
 const groups=paymentGroups(model);
 if(!groups.length)throw new Error(t('Yaklaşan hakediş kaydı bulunmuyor.'));
 const heading=upcomingPaymentTitle(company);
 const pages=paymentPages(groups);
 const dimensions=({contentHeight})=>{
  const height=fitToContent?contentHeight+page.margin:page.height;
  return {height,format:[page.width,height],orientation:height<page.width?'landscape':'portrait'};
 };
 const first=dimensions(pages[0]);
 const doc=new jsPDF({orientation:first.orientation,unit:'mm',format:first.format,compress:true});
 doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');
 doc.setProperties({title:heading,creator:'Omnigo Global'});
 doc.setFontSize(11.5);
 const amountWidth=groups.reduce((width,group)=>group.entries.reduce(
  (largest,item)=>Math.max(largest,doc.getTextWidth(money(item.amountCents))),width),0);
 for(const [index,sheet] of pages.entries()){
  const {height,format,orientation}=dimensions(sheet);
  if(index>0)doc.addPage(format,orientation);
  drawHeader(doc,heading,companyLogo,model,height);
  let y=page.top;
  for(const {name,entries} of sheet.groups)y+=drawMarketplace(doc,name,entries,marketLogos.get(name),y,amountWidth);
  if(sheet.last)drawExpectedTotal(doc,upcomingPaymentExpectedCents(model),y);
  drawFrame(doc,sheet.contentHeight);
 }
 return doc.output('blob');
}

export async function createUpcomingPaymentFile(model,company,{fitToContent=true}={}){
 const paidMarkets=model.rows.filter(row=>row.payments.size).map(row=>row.name);
 if(!paidMarkets.length)throw new Error(t('Yaklaşan hakediş kaydı bulunmuyor.'));
 const [companyLogo,images]=await Promise.all([
  companyExportLogo(company),
  Promise.all(paidMarkets.map(async name=>[name,await loadMarketLogo(name)])),
 ]);
 const blob=renderUpcomingPaymentPdf(model,company,{companyLogo,marketLogos:new Map(images),fitToContent});
 return {blob,filename:upcomingPaymentFilename(model,company)};
}
