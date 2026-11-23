import {text as i18nText} from './i18n.js';
import {currentLocale} from './i18n.js';
import {money} from './ui.js';
import {shipmentStatusLabel} from './shipment-status.js';
import {shipmentCustomerType} from './shipment-customer.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {copyTableText,tableCopyButton} from './table-copy-button.js';

export const shipmentTableCopyFields=new Set([
 'orderNumber','recipientName','invoiceName','productCode','recipientEmail','phone',
 'billingAddress','invoiceAddress','deliveryAddress','billingTaxOffice','billingTaxNumber','invoiceCents',
]);

export function shipmentCopyButton(key,label,{value}={}){
 if(!shipmentTableCopyFields.has(key))return '';
 return tableCopyButton({label:i18nText`${label} bilgisini kopyala`,
  data:{'shipment-copy':key,'shipment-copy-value':value},
  placement:'shipment-copy shipment-copy-inline'});
}
const billingCopyFields={billingAddress:'invoiceAddress',billingType:'customerType',billingTaxOffice:'taxOffice',billingTaxNumber:'taxNumber'};
export function shipmentCopyValue(row,key){
 if(Object.hasOwn(billingCopyFields,key))return shipmentInvoiceFields(row)[billingCopyFields[key]];
 if(key==='customerType')return shipmentCustomerType(row);
 if(key==='rowStatus')return shipmentStatusLabel(row);
 if(key==='invoiced')return `Fatura ${row.invoiced?'kesildi':'kesilmedi'} · ${shipmentStatusLabel(row)}`;
 if(key==='invoiceCents'){const amount=shipmentTableAmountCents(row);return amount===null?'':money(amount);}
 if(key==='orderedAt'&&row[key]){const date=new Date(row[key]);if(Number.isFinite(date.getTime()))return date.toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
 return String(row[key]??'');
}
export const copyShipmentCell=(row,key,value=shipmentCopyValue(row,key))=>copyTableText(value);
