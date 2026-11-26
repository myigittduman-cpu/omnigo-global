import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {jsPDF,autoTable} from './vendor/report-pdf-070.js';
import font from './vendor/report-font-070.js';
import {exportText} from './report-export-data.js';
import {drawCompanyPdfLogo} from './company-export-brand.js';
import {drawReportTableHeading} from './report-table-heading.js';
export async function createPdf(report,tables,logos,{titleInHeader=false}={}){
 const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4',compress:true});
 doc.addFileToVFS('NotoSans.ttf',font);doc.addFont('NotoSans.ttf','NotoSans','normal');doc.setFont('NotoSans');
 doc.setProperties({title:'Omnigo Global · '+exportText(report.company.name),subject:(report.title||i18nT('Satış ve hakediş raporu')),creator:i18nT('Omnigo Global')});
 const margin=14,headerHeight=titleInHeader?40:34;
 const header=table=>{
  const company=exportText(report.company.name);
  if(titleInHeader){
   drawReportTableHeading(doc,{margin,height:headerHeight,company,logo:logos[1],title:report.title||i18nT('Satış ve hakediş raporu'),
    context:report.from+' — '+report.to,section:table.name});
   return;
  }
  doc.setFillColor(17,29,45);doc.rect(0,0,297,headerHeight,'F');
  drawCompanyPdfLogo(doc,logos[1],14,8,44,17.6);
  drawCompanyPdfLogo(doc,logos[0],248,7,35,20);
  const textX=logos[1]?64:14;
  doc.setTextColor(232,214,172);doc.setFontSize(14);
  doc.setFontSize(Math.min(14,14*(240-textX)/Math.max(1,doc.getTextWidth(company))));
  doc.text(company,textX,12);
  doc.setFontSize(11);doc.setTextColor(231,236,242);doc.text((report.title||i18nT('Satış ve hakediş raporu')),textX,20);
  doc.setFontSize(8);doc.text(report.from+' — '+report.to,textX,28);
 };
 for(let index=0;index<tables.length;index++){
  if(index)doc.addPage();
  const table=tables[index];
  if(!titleInHeader){
   header(table);doc.setTextColor(25,39,57);doc.setFontSize(14);doc.text(table.name,margin,45);
  }
  const rows=table.rows.length?table.rows:[Array(table.head.length).fill('—')];
  autoTable(doc,{startY:titleInHeader?headerHeight:50,margin:{top:titleInHeader?headerHeight:43,bottom:16,left:margin,right:margin},head:[table.head],body:rows.map(row=>row.map((cell,i)=>cell==null?'—':typeof cell==='number'?new Intl.NumberFormat(currentLocale(),{minimumFractionDigits:table.money.includes(i)&&row[0]!==i18nT('Sipariş sayısı')?2:0,maximumFractionDigits:2}).format(cell):exportText(cell))),
   styles:{font:'NotoSans',fontStyle:'normal',fontSize:8,cellPadding:3,overflow:'linebreak',lineColor:[221,227,233],lineWidth:.1},headStyles:{fillColor:[29,44,63],textColor:[242,234,211],fontStyle:'normal'},alternateRowStyles:{fillColor:[246,248,250]},rowPageBreak:'avoid',
   willDrawPage:titleInHeader?()=>header(table):undefined,
   didDrawPage:titleInHeader?undefined:()=>header(table)});
 }
 const footer=report.footerNote?' · '+report.footerNote:i18nT(' · Kaynak ve kapsam son bölümde belirtilmiştir.');
 const pages=doc.getNumberOfPages();for(let page=1;page<=pages;page++){doc.setPage(page);doc.setFont('NotoSans');doc.setFontSize(7);doc.setTextColor(102,114,130);doc.text('Omnigo Global · '+new Date(report.generatedAt).toLocaleString(currentLocale())+footer,14,203);doc.text(`${page} / ${pages}`,282,203,{align:'right'});}
 return doc.output('blob');
}
