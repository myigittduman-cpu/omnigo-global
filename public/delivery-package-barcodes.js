import Code128 from './vendor/jsbarcode-code128-3.12.1.js';
import {t,text} from './i18n.js';

const quietModules=12;
const unsupportedCharacter=/[^A-Za-z0-9._-]/;

export function deliveryPackageLabels(order){
 const labels=Array.isArray(order.shippingLabels)&&order.shippingLabels.length?order.shippingLabels:[null];
 return labels.map(label=>{
  const packageId=typeof label?.packageId==='string'?label.packageId:'';
  const caption=packageId?text`Paket no: ${packageId}`:t('Paket barkodu');
  const value=label?.value;
  if(label?.format!=='CODE128'||typeof value!=='string'||!value.length||value.length>48||unsupportedCharacter.test(value))return {caption,value:null};
  const encoded=new Code128(value,{}).encode().data;
  const bars=Array.from(encoded.matchAll(/1+/g),match=>({start:quietModules+match.index,length:match[0].length}));
  return {caption,value,bars,modules:encoded.length+2*quietModules};
 });
}

export const missingPackageBarcode=()=>t('Pazaryerinden paket barkodu gelmedi');

export function printPackageBarcodes(doc,table,labels,startY){
 table({startY,body:labels.map(label=>[label.caption,label.value?'':missingPackageBarcode()]),
  styles:{font:'NotoSans',fontStyle:'normal',fontSize:9,cellPadding:2,overflow:'linebreak',textColor:20,valign:'middle',lineWidth:0},
  columnStyles:{0:{cellWidth:53},1:{cellWidth:224}},
  didParseCell:({row,column,cell})=>{
   if(column.index===1&&labels[row.index].value)cell.styles.minCellHeight=23;
  },
  didDrawCell:({row,column,cell})=>{
   const label=labels[row.index];
   if(column.index!==1||!label.value)return;
   const moduleWidth=Math.min(.4,(cell.width-4)/label.modules);
   const left=cell.x+2,top=cell.y+(cell.height-19)/2;
   doc.setFillColor(255);doc.rect(left,top,label.modules*moduleWidth,19,'F');
   doc.setFillColor(0);
   for(const bar of label.bars)doc.rect(left+bar.start*moduleWidth,top,bar.length*moduleWidth,13.5,'F');
   doc.setFont('NotoSans','normal');doc.setFontSize(9);doc.setTextColor(0);
   doc.text(label.value,left+label.modules*moduleWidth/2,top+18,{align:'center'});
  }
 });
}

// Excel embeds the symbol as a lossless, self-contained image. Integer module widths
// avoid interpolation while building the source image even with a high-DPI display.
export function workbookBarcodeImage(label){
 const pxPerModule=5,canvas=document.createElement('canvas');
 const widthMm=Math.min(label.modules*.4,161),heightMm=19;
 canvas.width=label.modules*pxPerModule;
 canvas.height=Math.round(canvas.width*heightMm/widthMm);
 const ctx=canvas.getContext('2d');
 ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);
 ctx.fillStyle='#000';
 const barHeight=Math.round(canvas.height*13.5/heightMm);
 for(const bar of label.bars)ctx.fillRect(bar.start*pxPerModule,0,bar.length*pxPerModule,barHeight);
 ctx.textAlign='center';ctx.textBaseline='bottom';ctx.font=Math.round(canvas.height*3.5/heightMm)+'px Arial';
 ctx.fillText(label.value,canvas.width/2,canvas.height-1);
 return {base64:canvas.toDataURL('image/png'),width:widthMm*96/25.4,height:heightMm*96/25.4};
}
