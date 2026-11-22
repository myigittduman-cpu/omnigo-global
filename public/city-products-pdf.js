import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {drawCompanyPdfLogo,exportCompanyName} from './company-export-brand.js';
import {cityProductsColumns} from './city-products-document.js';
import {orderDate} from './delivery-view.js';
import {t} from './i18n.js';

const navy=[28,38,53],gold=[230,206,150],line=[201,177,118];
const widths=[24,30,25,53,19,19,32,55,14];
export function cityProductsPdf(model,logo){
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4',compress:true,putOnlyUsedFonts:true});
 doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');
 const brand=exportCompanyName(model.company);
 doc.setProperties({title:brand+' · '+model.title,creator:'Omnigo Global'});
 const body=model.rows.map(row=>[row.first?orderDate(row.orderedAt):'',row.recipient,row.phone,row.address,row.city,row.district,row.sku,row.name,row.quantity]);
 autoTable(doc,{startY:34,margin:{top:34,left:13,right:13,bottom:14},tableWidth:271,theme:'grid',rowPageBreak:'avoid',
  head:[cityProductsColumns.map(label=>t(label))],body,
  styles:{font:'NotoSans',fontStyle:'normal',fontSize:8,overflow:'linebreak',cellPadding:2,valign:'middle',lineWidth:.14,lineColor:line},
  headStyles:{fillColor:navy,textColor:gold,minCellHeight:9,fontStyle:'normal'},
  columnStyles:Object.fromEntries(widths.map((cellWidth,i)=>[i,{cellWidth,...(i===8?{halign:'right'}:{})}])),
  didParseCell:({section,row,cell})=>{if(section==='body')cell.styles.fillColor=model.rows[row.index].orderIndex%2?[243,246,250]:[255,255,255];}
 });
 const pages=doc.getNumberOfPages(),width=doc.internal.pageSize.getWidth(),height=doc.internal.pageSize.getHeight();
 for(let page=1;page<=pages;page++){
  doc.setPage(page);doc.setFillColor(...navy);doc.rect(13,7,width-26,25,'F');
  const start=logo?65:17;drawCompanyPdfLogo(doc,logo,17,10,45,18);
  const title=brand+' · '+model.title;doc.setFontSize(15);doc.setFontSize(Math.min(15,15*(width-start-18)/Math.max(1,doc.getTextWidth(title))));
  doc.setTextColor(...gold);doc.text(title,start,17);
  doc.setFontSize(9);doc.setTextColor(242,244,247);doc.text(model.city+' · '+model.summary,start,25);
  doc.setDrawColor(...line);doc.line(13,height-11,width-13,height-11);
  doc.setFontSize(8);doc.setTextColor(...navy);doc.text(brand,13,height-7);doc.text(page+' / '+pages,width-13,height-7,{align:'right'});
 }
 return doc.output('blob');
}
