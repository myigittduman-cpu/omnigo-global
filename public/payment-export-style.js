/** Presentation only: an approved receipt remains separate from an expected payment. */
export const receivedExportColors={fill:[231,245,236],text:[25,99,53],green:[37,132,74],fillArgb:'FFE7F5EC',textArgb:'FF196335'};

export const receivedExportRow=(table,index)=>table.receivedRows?.includes(index)===true;

export function drawReceivedExportCheck(doc,x,y,radius=2){
 doc.saveGraphicsState();
 doc.setFillColor(...receivedExportColors.green);doc.circle(x,y,radius,'F');
 doc.setDrawColor(255,255,255);doc.setLineWidth(radius*.24);doc.setLineCap('round');doc.setLineJoin('round');
 doc.line(x-radius*.5,y,x-radius*.1,y+radius*.4);doc.line(x-radius*.1,y+radius*.4,x+radius*.55,y-radius*.4);
 doc.restoreGraphicsState();
}
