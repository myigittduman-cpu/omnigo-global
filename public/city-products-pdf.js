import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {exportCompanyName} from './company-export-brand.js';
import {drawReportTableHeading} from './report-table-heading.js';
import {cityProductsColumns} from './city-products-document.js';
import {orderDate} from './delivery-view.js';
import {t} from './i18n.js';

const navy=[28,38,53],gold=[230,206,150],line=[201,177,118];
const widths=[24,30,25,52,19,19,32,54,14];
export function cityProductsPdf(model,logo){
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4',compress:true,putOnlyUsedFonts:true});
 doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');
 const brand=exportCompanyName(model.company);
 doc.setProperties({title:brand+' · '+model.title,creator:'Omnigo Global'});
 const body=model.rows.map(row=>[row.first?orderDate(row.orderedAt):'',row.recipient,row.phone,row.address,row.city,row.district,row.sku,row.name,row.quantity]);
 const margin=14,headerHeight=34;
 const width=doc.internal.pageSize.getWidth(),height=doc.internal.pageSize.getHeight();
 autoTable(doc,{startY:headerHeight,margin:{top:headerHeight,left:margin,right:margin,bottom:14},tableWidth:width-2*margin,theme:'grid',rowPageBreak:'avoid',
  head:[cityProductsColumns.map(label=>t(label))],body,
  styles:{font:'NotoSans',fontStyle:'normal',fontSize:8,overflow:'linebreak',cellPadding:2,valign:'middle',lineWidth:.14,lineColor:line},
  headStyles:{fillColor:navy,textColor:gold,minCellHeight:9,fontStyle:'normal'},
  columnStyles:Object.fromEntries(widths.map((cellWidth,i)=>[i,{cellWidth,...(i===8?{halign:'right'}:{})}])),
  didParseCell:({section,row,cell})=>{if(section==='body')cell.styles.fillColor=model.rows[row.index].orderIndex%2?[243,246,250]:[255,255,255];},
  willDrawPage:()=>drawReportTableHeading(doc,{margin,height:headerHeight,company:brand,logo,title:model.title,context:model.city+' · '+model.summary}),
 });
 const pages=doc.getNumberOfPages();
 for(let page=1;page<=pages;page++){
  doc.setPage(page);doc.setDrawColor(...line);doc.line(margin,height-11,width-margin,height-11);
  doc.setFontSize(8);doc.setTextColor(...navy);doc.text(brand,margin,height-7);doc.text(page+' / '+pages,width-margin,height-7,{align:'right'});
 }
 return doc.output('blob');
}
