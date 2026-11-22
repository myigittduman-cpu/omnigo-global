// Shared presentation only: source values and business calculations stay in their exporters.
export const workbookColors={navy:'FF1C2B3D',gold:'FFBEA46B',ink:'FF24364B',muted:'FF627187',line:'FF8D9BAD',stripe:'FFF3F6FA',white:'FFFFFFFF'};
export const workbookFormats={money:'#,##0.00 "TL";[Red]-#,##0.00 "TL"',integer:'#,##0',date:'dd.mm.yyyy',dateTime:'dd.mm.yyyy hh:mm',text:'@'};
export const workbookText=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'');
export const solidFill=color=>({type:'pattern',pattern:'solid',fgColor:{argb:color}});
export const workbookFont=(extra={})=>({name:'Arial',size:11,color:{argb:workbookColors.ink},...extra});
export const workbookBorders=(color=workbookColors.line)=>Object.fromEntries(['top','bottom','left','right'].map(side=>[side,{style:'thin',color:{argb:color}}]));

export function configureWorkbook(workbook,created=new Date(),company='Firma'){
 workbook.creator=workbookText(company);workbook.company=workbookText(company);
 workbook.created=Number.isFinite(new Date(created).getTime())?new Date(created):new Date();
 workbook.modified=workbook.created;
}
export function configureSheet(sheet,{headerRow=7,frozenColumns=1,paperSize=8,form=false,company='Firma'}={}){
 sheet.properties.defaultRowHeight=24;sheet.properties.tabColor={argb:workbookColors.navy};
 sheet.views=[{showGridLines:false,zoomScale:90,...(!form?{state:'frozen',xSplit:frozenColumns,ySplit:headerRow}:{} )}];
 sheet.pageSetup={orientation:'landscape',paperSize,fitToPage:true,fitToWidth:1,fitToHeight:0,
  margins:{left:.3,right:.3,top:.4,bottom:.4,header:.15,footer:.2},...(!form?{printTitlesRow:`${headerRow}:${headerRow}`}:{})};
 sheet.headerFooter.oddFooter='&L&"Arial"&9'+workbookText(company).replaceAll('&','&&').slice(0,160)+'&RSayfa &P / &N';
}
export function mergedText(sheet,row,first,last,value,{size=11,bold=false,color=workbookColors.ink,height=26,fill=null}={}){
 if(last>first)sheet.mergeCells(row,first,row,last);
 const cell=sheet.getCell(row,first);cell.value=workbookText(value);cell.font=workbookFont({size,bold,color:{argb:color}});
 cell.alignment={vertical:'middle',horizontal:'left',wrapText:true};
 if(fill)cell.fill=solidFill(fill);sheet.getRow(row).height=height;return cell;
}
export function workbookBrandHeading(sheet,{row,columns,brand,title,logo,partnerLogo}){
 const first=logo&&columns>1?2:1;
 const partner=partnerLogo&&columns>first;
 const last=partner?columns-1:columns;
 const lines=[[brand,15,workbookColors.gold],[title,12,workbookColors.white]];
 for(const [index,[value,size,color]] of lines.entries()){
  for(let column=1;column<=columns;column++)sheet.getCell(row+index,column).fill=solidFill(workbookColors.navy);
  const cell=mergedText(sheet,row+index,first,last,value,{size,bold:true,color,height:26,fill:workbookColors.navy});
  cell.alignment={vertical:'middle',horizontal:'left',indent:1,shrinkToFit:true};
 }
 if(first===2)addHeaderImage(sheet,logo,1,row,148);
 if(partner)addHeaderImage(sheet,partnerLogo,columns,row,76);
}
function addHeaderImage(sheet,image,column,row,maximumWidth){
 const columnPixels=(sheet.getColumn(column).width||9)*7+5;
 const rowPixels=sheet.getRow(row).height*4/3;
 const totalHeight=rowPixels+sheet.getRow(row+1).height*4/3;
 const scale=Math.min(maximumWidth/image.width,(columnPixels-10)/image.width,(totalHeight-10)/image.height);
 const width=image.width*scale,height=image.height*scale;
 addWorkbookLogo(sheet.workbook,sheet,image,{column:column-1+(columnPixels-width)/(2*columnPixels),
  row:row-1+(totalHeight-height)/(2*rowPixels),width,height});
}
export function tableHeading(sheet,{title,context,note,columns,brand='Firma',logo}){
 columns=Math.min(columns,7);
 sheet.getRow(1).height=8;
 workbookBrandHeading(sheet,{row:2,columns,brand,title,logo});
 mergedText(sheet,4,1,columns,note,{color:workbookColors.muted,height:27});
 mergedText(sheet,5,1,columns,context,{height:24});
 sheet.getRow(6).height=10;
}
export function styleHeader(row,columns){
 row.height=34;
 for(let index=1;index<=columns;index++){
  const cell=row.getCell(index);cell.fill=solidFill(workbookColors.navy);
  cell.font=workbookFont({bold:true,color:{argb:workbookColors.white}});
  cell.alignment={horizontal:'center',vertical:'middle',wrapText:true};
  cell.border={...workbookBorders(),bottom:{style:'medium',color:{argb:workbookColors.gold}}};
 }
}
export function styleBody(row,widths,{index=0,money=[],integers=[],dates=[],texts=[]}={}){
 let lines=1;
 for(let column=1;column<=widths.length;column++){
  const cell=row.getCell(column),value=cell.value;
  cell.font=workbookFont();cell.fill=solidFill(index%2?workbookColors.stripe:workbookColors.white);
  cell.border=workbookBorders();
  cell.alignment={vertical:'middle',horizontal:typeof value==='number'?'right':'left',wrapText:true};
  if(money.includes(column))cell.numFmt=workbookFormats.money;
  else if(integers.includes(column))cell.numFmt=workbookFormats.integer;
  else if(dates.includes(column))cell.numFmt=workbookFormats.dateTime;
  else if(texts.includes(column))cell.numFmt=workbookFormats.text;
  const content=value instanceof Date?'01.01.2026 00:00':value;
  for(const line of String(content??'').split(/\r?\n/))lines=Math.max(lines,Math.ceil([...line].length/Math.max(8,widths[column-1]-3)));
 }
 row.height=Math.min(409,Math.max(30,lines*15+12));
}
// Excel stores wall-clock dates without a zone. Keep the displayed date in Türkiye.
export function turkeyWorkbookDate(value){
 if(!value)return null;
 const raw=String(value),local=/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(raw);
 const date=new Date(local?raw.replace(' ','T')+'+03:00':value);if(!Number.isFinite(date.getTime()))return null;
 const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Istanbul',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(date).map(part=>[part.type,part.value]));
 return new Date(Date.UTC(+parts.year,+parts.month-1,+parts.day,+parts.hour,+parts.minute,+parts.second));
}
export function calendarWorkbookDate(value){
 if(!/^\d{4}-\d{2}-\d{2}$/.test(String(value)))return value;
 const date=new Date(value+'T00:00:00Z');return Number.isFinite(date.getTime())?date:value;
}
export function addWorkbookLogo(workbook,sheet,image,{column=0,row=0,width=130,height=50}={}){
 if(!image?.data||!image.width||!image.height)return;
 const scale=Math.min(width/image.width,height/image.height);
 const id=workbook.addImage({base64:image.data,extension:'png'});
 sheet.addImage(id,{tl:{col:column,row},ext:{width:image.width*scale,height:image.height*scale},editAs:'oneCell'});
}
// Previously loaded exporters can finish a download across an application update.
export function addWorkbookBrand(workbook,sheet,image){
 if(!image?.data)return;
 sheet.getRow(1).height=78;
 addWorkbookLogo(workbook,sheet,image,{column:.04,row:.08,width:260,height:88});
}
export function addWorkbookTable(sheet,columns,headerRow=7){
 if(sheet.rowCount<=headerRow)return;
 const rows=[];
 for(let row=headerRow+1;row<=sheet.rowCount;row++)rows.push(Array.from({length:columns},(_,index)=>sheet.getCell(row,index+1).value));
 sheet.addTable({name:'ReportTable'+sheet.id,ref:'A'+headerRow,headerRow:true,totalsRow:false,
  style:{theme:'TableStyleMedium2',showRowStripes:false},
  columns:Array.from({length:columns},(_,index)=>({name:String(sheet.getCell(headerRow,index+1).value),filterButton:true})),rows});
}
export async function workbookBlob(workbook){
 return new Blob([await workbook.xlsx.writeBuffer()],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
}
