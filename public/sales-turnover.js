import {money} from './ui.js';
import {t} from './i18n.js';

/** Display projection only; never replaces invoice/net fields in source data. */
export const salesTurnoverVisible=data=>data?.sensitiveVisibility?.turnover!==false&&data?.workspaceAccess?.storeRole!=='accounting_logistics';
export const customerTurnover=row=>row?.turnoverDisplayBasis==='after_cancellations'||row?.turnoverDisplayBasis==='before_cancellations'||row?.sensitiveVisibility?.cancellations===false;

export function turnoverValue(row){
 if(row?.turnoverDisplayBasis==='after_cancellations'||row?.turnoverDisplayBasis==='before_cancellations')return rawTurnoverValue(row);
 if(Object.hasOwn(row||{},'salesTurnoverCents'))return Number.isSafeInteger(row.salesTurnoverCents)?row.salesTurnoverCents:null;
 if(!Object.hasOwn(row||{},'turnoverCents')&&!Object.hasOwn(row||{},'grossCents'))return rawTurnoverValue(row);
 const gross=rawTurnoverValue(row),cancelled=cancelledTurnoverValue(row);
 return gross===null||cancelled===null?null:gross-cancelled;
}
export function rawTurnoverValue(row){const value=Object.hasOwn(row||{},'turnoverCents')?row.turnoverCents:(row?.grossCents??row?.salesCents??row?.amountCents??row?.netCents);return Number.isSafeInteger(value)?value:null;}
export function cancelledTurnoverValue(row){if(!row)return null;const value=Object.hasOwn(row,'cancelledTurnoverCents')?row.cancelledTurnoverCents:(row.cancelCents??0);return Number.isSafeInteger(value)?value:null;}
export const cancelledTurnoverMoney=row=>cancelledTurnoverValue(row)===null?t('Bekleniyor'):money(cancelledTurnoverValue(row));
export const rawTurnoverMoney=row=>rawTurnoverValue(row)===null?t('Bekleniyor'):money(rawTurnoverValue(row));
export const turnoverAverage=row=>row?.orders&&turnoverValue(row)!==null?Math.round(turnoverValue(row)/row.orders):null;
export const turnoverMoney=row=>turnoverValue(row)===null?t('Bekleniyor'):money(turnoverValue(row));
export function turnoverTotal(rows,valueOf=turnoverValue){
 const values=rows.map(valueOf);return values.every(Number.isSafeInteger)?values.reduce((sum,value)=>sum+value,0):null;
}
export const turnoverDays=(days,valueOf=turnoverValue)=>(days||[]).map(day=>({...day,amountCents:valueOf(day)}));
export const turnoverNote='Satış tutarından iptaller çıkarılır. İadeler, komisyon ve giderler bu tutardan düşülmez; bu rakam hesaba geçen ödeme değildir. Sipariş ve sevkiyat tabloları fatura tutarını kullanır.';
export const turnoverLabel=row=>row?.turnoverDisplayBasis==='after_cancellations'?'İptal sonrası ciro':customerTurnover(row)?'Ciro':'İptaller sonrası ciro';
export const turnoverDescription=row=>customerTurnover(row)&&row?.turnoverDisplayBasis!=='after_cancellations'?'Ciro, pazaryerlerinden aktarılan satış tutarıdır. Sipariş ve sevkiyat tablolarında fatura tutarı gösterilir.':turnoverNote;
