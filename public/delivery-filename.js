import {documentFilename} from './document-filename.js';
import {deliveryCities} from './delivery-city-filter.js';
import {t as i18nT} from './i18n.js';
export function deliveryFilename(city,type='pdf',context={}){
 if(Array.isArray(city)){
  if(!city.length||city.some(value=>!deliveryCities.includes(value)))throw new Error(i18nT('Teslimat ilini seçin.'));
  return documentFilename({...context,city:city[0],type,kind:'delivery'});
 }
 return documentFilename({...context,city,type,kind:'delivery'});
}
