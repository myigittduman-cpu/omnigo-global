import {t as i18nT,text as i18nText} from './i18n.js';
import {currentLocale} from './i18n.js';
import {money} from './ui.js';
import {shipmentStatusLabel} from './shipment-status.js';
import {shipmentCustomerType} from './shipment-customer.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';
import {withApplicationCopy} from './login-content.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {tableCopyButton} from './table-copy-button.js';

export function shipmentCopyButton(key,label,{inline=false,value}={}){
 return tableCopyButton({label:i18nText`${label} bilgisini kopyala`,
  data:{'shipment-copy':key,'shipment-copy-value':value},
  placement:inline?'shipment-copy shipment-copy-inline':'shipment-copy'});
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
export async function copyShipmentCell(row,key,value=shipmentCopyValue(row,key)){
 if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(value);return;}
 const input=document.createElement('textarea'),active=document.activeElement;
 input.value=value;input.readOnly=true;input.style.cssText='position:fixed;left:-9999px;top:0';document.body.append(input);
 try{input.select();if(!withApplicationCopy(()=>document.execCommand('copy')))throw new Error(i18nT('Kopyalama yapılamadı. Tarayıcının pano iznini kontrol edin.'));}
 finally{input.remove();active?.focus({preventScroll:true});}
}
