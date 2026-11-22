import {labelRows} from './i18n.js';
// Export layout is independent of the interactive table's grouped/status controls.
export const shipmentExportColumns=labelRows([
 ['orderedAt','Sipariş tarihi ve saati'],['recipientName','Alıcı adı'],['recipientEmail','Alıcı maili'],
 ['phone','İletişim no'],['deliveryAddress','Teslimat adresi'],['productName','Ürün adı'],['quantity','Adet'],
 ['invoiceCents','Fatura tutarı'],['invoiceName','Fatura adı'],['invoiceAddress','Fatura adresi'],
 ['customerType','Müşteri tipi'],['taxNumber','Vergi numarası'],['taxOffice','Vergi dairesi'],['orderNumber','Sipariş numarası']
]);
export function shipmentInvoiceFields(row){
 let address=String(row.invoiceAddress??'');
 let number=String(row.taxNumber??'').trim(),office=String(row.taxOffice??'').trim();
 address=address.replace(/(?:Vergi\s*(?:numarası|numarasi|no(?:su)?\.?|num\.?|kimlik\s*(?:numarası|no))|V\.?\s*K\.?\s*N\.?)\s*[:：]\s*(\d{10,11})(?!\d)/giu,(_,value)=>{number||=value;return '';});
 address=address.replace(/(?:Vergi\s*dairesi|V\s*\.\s*D\s*\.?)\s*[:：]\s*([^\n\r]+)/giu,(_,value)=>{office||=value.trim();return '';});
 const type=String(row.customerType??'').split(/\r?\n/)[0].trim();
 return {invoiceAddress:address.replace(/[ \t]+/g,' ').replace(/ *\n */g,'\n').trim(),customerType:type,taxNumber:type==='Kurumsal'?number:'',taxOffice:type==='Kurumsal'?office:''};
}
