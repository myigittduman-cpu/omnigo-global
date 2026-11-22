import {t as i18nT} from './i18n.js';
export function shipmentCustomerType(row){
 return [i18nT(row.customerType||''),row.customerType==='Kurumsal'&&row.taxNumber?String(row.taxNumber):''].filter(Boolean).join('\n');
}
