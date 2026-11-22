import test from 'node:test';import assert from 'node:assert/strict';
import {paymentReceiptPayload} from '../public/payment-receipt.js';
import {paymentReceiptView} from '../public/payment-receipt-form.js';
const record={id:7,version:3,channel:'n11',expectedDate:'2026-10-31',expectedCents:3061122,source:'Panel',status:'pending',verification:'confirmed',verificationSource:'Ekstre',periodStart:'2026-10-01',periodEnd:'2026-10-31',taxBasis:'included',reference:'REF-7',notes:'Korunan not',details:[{section:'tax',label:'Vergi',amountCents:125,rateBasisPoints:null}]};
test('Ödeme kaydı hakediş tutarını, kalemleri ve ekstre bilgilerini değiştirmeden gerçek banka tutarını işler',()=>{
 const before=structuredClone(record),payload=paymentReceiptPayload(record,{actualCents:'30.500,22',paidDate:'2026-11-02',confirmation:' Banka dekontu '},'2026-11-02');
 assert.equal(payload.status,'paid');assert.equal(payload.actualCents,3050022);assert.equal(payload.paidDate,'2026-11-02');assert.equal(payload.confirmation,'Banka dekontu');assert.equal(payload.version,3);
 for(const key of ['expectedCents','expectedDate','source','reference','verificationSource','periodStart','periodEnd','taxBasis','notes','details'])assert.deepEqual(payload[key],record[key]);assert.deepEqual(record,before);assert.notEqual(payload.details,record.details);
});
test('Geçerli tarih, tutar ve sürüm zorunludur. Ödeme kaynağı isteğe bağlıdır.',()=>{
 const entry={actualCents:'30.500,22',paidDate:'2026-11-02',confirmation:'Banka ekstresi'};
 for(const patch of [{actualCents:''},{actualCents:'1e9'},{actualCents:'-2'},{paidDate:'2026-02-30'},{paidDate:'2026-11-03'}])assert.throws(()=>paymentReceiptPayload(record,{...entry,...patch},'2026-11-02'));
 assert.equal(paymentReceiptPayload(record,{actualCents:entry.actualCents,paidDate:entry.paidDate},'2026-11-02').confirmation,'');
 for(const patch of [{integration:'old'},{archivedAt:'2026-01-01'},{version:undefined},{id:undefined}])assert.throws(()=>paymentReceiptPayload({...record,...patch},entry,'2026-11-02'));
});
test('Ödeme formu yalnız banka bilgilerini düzenler ve veri metnini güvenli gösterir',()=>{
 const html=paymentReceiptView({...record,channel:'<script>',confirmation:'" onfocus="evil'},'2026-11-02');assert.doesNotMatch(html,/<script>|name="expectedCents"|name="channel"|name="confirmation"/);assert.match(html,/&lt;script&gt;/);assert.match(html,/name="actualCents"/);assert.match(html,/name="paidDate"/);
});
