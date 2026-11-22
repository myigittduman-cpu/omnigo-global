import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentBreakdownGroups,paymentBreakdown} from '../public/payment-breakdown.js';

const pending={inCalendar:true,calendarDate:'2026-10-04',channel:'Koçtaş',status:'pending',verification:'estimated',expectedCents:10000,actualCents:0};
test('Gün ve platform toplamları tahmini, teyitli ve alınan tutarları birbirine karıştırmaz',()=>{
 const items=[pending,{...pending,expectedCents:5000},{...pending,verification:'confirmed',expectedCents:2000},{...pending,status:'paid',actualCents:9000,expectedCents:12000,paymentApproval:{at:'2026-11-07T00:00:00Z'}},{...pending,channel:'Pazarama',expectedCents:3000},{...pending,inCalendar:false,expectedCents:999999}];
 const [day]=paymentBreakdownGroups(items);
 assert.equal(day.day,'2026-10-04');
 assert.equal(day.channels.length,2);
 assert.deepEqual(Object.fromEntries(day.channels[0].amounts),{estimated:15000,confirmed:2000,received:9000});
 assert.equal(day.channels[1].amounts.get('estimated'),3000);
});
test('Pazaryeri gönderimi beklenen veya banka teyitli toplamına karışmaz',()=>{
 const rows=[{...pending,status:'sent',verification:'confirmed',expectedCents:2500},{...pending,excludedFromTotals:true,expectedCents:99999}];
 assert.deepEqual(Object.fromEntries(paymentBreakdownGroups(rows)[0].channels[0].amounts),{sent:2500});
 assert.match(paymentBreakdown(rows),/Aktarımı bildirilen/);
});

test('Onaysız kayıtlı banka tutarı yalnız aktarımı bildirilen grubundadır',()=>{
 const reported={...pending,status:'paid',expectedCents:10000,actualCents:9000};
 assert.deepEqual(Object.fromEntries(paymentBreakdownGroups([reported])[0].channels[0].amounts),{sent:9000});
 assert.match(paymentBreakdown([reported]),/Aktarımı bildirilen/);
 assert.doesNotMatch(paymentBreakdown([reported]),/Hesaba geçen/);
});
test('Gün seçimi, sıralama ve boş gün takvimle aynı kayıtları gösterir',()=>{
 const items=[{...pending,calendarDate:'2026-10-12'},pending];
 assert.deepEqual(paymentBreakdownGroups(items).map(row=>row.day),['2026-10-04','2026-10-12']);
 assert.equal(paymentBreakdownGroups(items,'2026-10-12').length,1);
 assert.deepEqual(paymentBreakdownGroups(items,'2026-10-03'),[]);
 assert.match(paymentBreakdown(items,'2026-10-03'),/3 Ekim 2026 için ödeme kaydı bulunmuyor/);
 assert.ok(!paymentBreakdown([{...pending,channel:'<img src=x>'}]).includes('<img src=x>'));
});
