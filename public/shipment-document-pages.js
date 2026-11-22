export const shipmentDocumentPages=Object.freeze({
 shipmentInvoices:Object.freeze({kind:'invoice',name:'Faturalar',pending:'Bekleyen Faturalar',completed:'Tamamlanan Faturalar',upload:'Fatura Yükle'})
});

export const isShipmentDocumentPage=page=>Object.hasOwn(shipmentDocumentPages,page);
