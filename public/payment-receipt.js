import {parseMoneyAmount} from './money-value.js';
import {validCalendarDate} from './calendar-date.js';
import {paymentIsApproved} from './payment-approval.js';
export function paymentReceiptPayload(record,entry,today){
 if(!Number.isInteger(record?.id)||!Number.isInteger(record?.version)||record.integration||record.archivedAt||paymentIsApproved(record))throw new Error('Bu hakedişe ödeme bilgisi girilemez.');
 const actualCents=parseMoneyAmount(entry.actualCents);
 if(!validCalendarDate(entry.paidDate)||entry.paidDate>today)throw new Error('Hesaba geçiş tarihini kontrol edin; gelecekte bir tarih seçilemez.');
 const confirmation=String(entry.confirmation??(record.actualCents===actualCents&&record.paidDate===entry.paidDate?record.confirmation||'':'')).trim();
 if(confirmation.length>250)throw new Error('Banka teyit kaynağı çok uzun.');
 const preserved=['channel','expectedDate','expectedCents','source','verification','verificationSource','periodStart','periodEnd','taxBasis','reference','notes'];
 const payload=Object.fromEntries(preserved.filter(key=>record[key]!==undefined).map(key=>[key,record[key]]));
 return {...payload,details:(record.details||[]).map(row=>({...row})),version:record.version,status:'paid',actualCents,paidDate:entry.paidDate,confirmation};
}
