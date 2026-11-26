import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {drawCompanyPdfLogo} from './company-export-brand.js';

const left=10,right=287;

export function deliveryPdfLayout({title,company}){
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
 doc.addFileToVFS('NotoSans.ttf',font);
 doc.addFont('NotoSans.ttf','NotoSans','normal');
 doc.setFont('NotoSans');
 doc.setProperties({title,subject:company,creator:'OmniGoGlobal'});
 const table=options=>autoTable(doc,{margin:{top:35,bottom:14,left,right:10},theme:'plain',styles:{font:'NotoSans',fontStyle:'normal',fontSize:10,cellPadding:{top:1.3,bottom:1.3,left:2,right:2},overflow:'linebreak',textColor:20,lineWidth:0},rowPageBreak:'avoid',...options});
 const details=(startY,fields,separators)=>table({
  startY,body:fields.map(([label,value])=>[label,':',value||'—']),
  columnStyles:{0:{cellWidth:53},1:{cellWidth:5},2:{cellWidth:219}},
  didDrawCell:({column,row,cell})=>{
   if(column.index!==0||!separators.includes(row.index))return;
   doc.setDrawColor(90);doc.setLineWidth(.15);doc.line(left,cell.y,right,cell.y);
  },
 });
 const products=(startY,head,body,columnStyles)=>table({
  startY,theme:'grid',head:[head],body,columnStyles,
  styles:{font:'NotoSans',fontStyle:'normal',fontSize:10,cellPadding:3,lineWidth:.15,lineColor:[90,90,90],minCellHeight:18},
  headStyles:{fillColor:[245,245,245],textColor:20,minCellHeight:9,fontStyle:'normal'},
 });
 const heading=(page,{logo,marketplaceLogo,subtitle,summary,footer})=>{
  doc.setPage(page);
  drawCompanyPdfLogo(doc,logo,left,5,45,18);
  drawCompanyPdfLogo(doc,marketplaceLogo,right-35,6,35,18);
  const textX=logo?left+52:left,textWidth=right-(marketplaceLogo?48:0)-textX;
  doc.setFont('NotoSans');doc.setFontSize(14);doc.setTextColor(28,38,53);
  doc.setFontSize(Math.min(14,14*textWidth/Math.max(1,doc.getTextWidth(company))));
  doc.text(company,textX,11);
  doc.setFontSize(11);doc.text(doc.splitTextToSize(subtitle,textWidth).slice(0,2),textX,18);
  doc.setFontSize(8);doc.setTextColor(60);doc.text(doc.splitTextToSize(summary,textWidth).slice(0,2),textX,25);
  doc.setDrawColor(199,170,114);doc.setLineWidth(.3);doc.line(left,32,right,32);
  doc.setTextColor(95);doc.text(footer,left,202);doc.text(`${page} / ${doc.getNumberOfPages()}`,right,202,{align:'right'});
 };
 return {doc,table,details,products,heading};
}
