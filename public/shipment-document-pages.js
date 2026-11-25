export const shipmentDocumentPages=Object.freeze({
 shipmentInvoices:Object.freeze({kind:'invoice',name:'Faturalar',upload:'Fatura Yükle'})
});

export const isShipmentDocumentPage=page=>Object.hasOwn(shipmentDocumentPages,page);
