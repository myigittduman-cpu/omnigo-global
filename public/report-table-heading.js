import {drawCompanyPdfLogo} from './company-export-brand.js';

export function drawReportTableHeading(doc,{margin,height,company,logo,marketplaceLogo,title,context,section}){
 const right=doc.internal.pageSize.getWidth()-margin;
 const logos=[logo,marketplaceLogo].filter(Boolean);
 const textX=margin+4+logos.length*50,textWidth=right-textX-6;
 doc.setFont('NotoSans');
 doc.setFillColor(17,29,45);doc.rect(margin,0,right-margin,height,'F');
 logos.forEach((asset,index)=>drawCompanyPdfLogo(doc,asset,margin+4+index*50,8,44,17.6));
 const line=(value,y,size,color)=>{
  if(!value)return;
  doc.setTextColor(...color);
  doc.setFontSize(size);
  doc.setFontSize(Math.min(size,size*textWidth/Math.max(1,doc.getTextWidth(value))));
  doc.text(value,textX,y);
 };
 line(company,12,14,[232,214,172]);
 line(title,20,11,[231,236,242]);
 line(context,27,8,[231,236,242]);
 line(section,35,12,[242,234,211]);
}
