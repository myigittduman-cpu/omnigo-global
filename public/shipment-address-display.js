import {currentLanguage,html,t} from './i18n.js';
import {esc} from './ui.js';
import {shipmentCopyButton} from './shipment-copy.js';
import {shipmentInvoiceFields} from './shipment-export-fields.js';

const sameAsBilling={tr:'Fatura adresi ile aynı',en:'Same as billing address',de:'Wie Rechnungsadresse'};

export function shipmentAddressKey(value){
 return String(value??'').normalize('NFC').trim().replace(/\s+/gu,' ').toLocaleLowerCase('tr-TR');
}

export function sameShipmentAddress(row,billingAddress=shipmentInvoiceFields(row).invoiceAddress){
 const delivery=shipmentAddressKey(row.deliveryAddress);
 return /[\p{L}\p{N}]/u.test(delivery)&&delivery===shipmentAddressKey(billingAddress);
}

export function shipmentDeliveryAddressText(row,billingAddress){
 if(sameShipmentAddress(row,billingAddress))return sameAsBilling[currentLanguage()]??sameAsBilling.tr;
 return String(row.deliveryAddress??'').trim()?row.deliveryAddress:'—';
}

export function shipmentDeliveryLocation(row,copyParts=false){
 const part=(key,label)=>{
  const value=String(row[key]??'').trim();
  const copy=copyParts&&value?shipmentCopyButton(key,t(label),{inline:true,value}):'';
  return html`<span>${esc(value||'—')}${copy}</span>`;
 };
 return html`<div class="shipment-delivery-location"><small class="shipment-grouped-secondary">${t('İl')} / ${t('İlçe')}</small><div class="shipment-grouped-location">${part('province','İl')} / ${part('district','İlçe')}</div></div>`;
}
