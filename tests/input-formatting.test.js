import test from 'node:test';
import assert from 'node:assert/strict';
import {formatMoneyEntry,parseMoneyAmount,displayMoneyAmount,canonicalMoneyEntry} from '../public/money-value.js';
import {maskDateEntry,parseDateEntry} from '../public/manual-date-value.js';
import {shipmentDraftRows} from '../public/shipment-edit-session.js';

test('Ayraçsız gün, dönem ve saat girişi yazarken biçimlenir; geçersiz tarih düzeltilmiş sayılmaz',()=>{
 for(const [raw,mode,display,iso] of [
  ['30102026','date','30.10.2026','2026-10-30'],['102026','month','10.2026','2026-10'],
  ['301020261530','datetime-local','30.10.2026 15:30','2026-10-30T15:30'],
  ['30102026153045','datetime-local','30.10.2026 15:30:45','2026-10-30T15:30:45'],
  ['2026-10-30','date','30.10.2026','2026-10-30'],['1/2/2026','date','01.02.2026','2026-02-01']
 ]){assert.equal(maskDateEntry(raw,mode),display);assert.equal(parseDateEntry(display,mode),iso);}
 assert.equal(maskDateEntry('301'),'30.1');assert.equal(maskDateEntry('3010202615','datetime-local'),'30.10.2026 15');
 assert.equal(maskDateEntry('2.10.2026','date',{editing:true}),'2.10.2026');
 assert.equal(maskDateEntry('31022026'),'31.02.2026');assert.equal(parseDateEntry(maskDateEntry('31022026')),'');
 assert.equal(maskDateEntry('301020260'),'301020260');assert.equal(maskDateEntry('abc'),'abc');
});

test('Tutarlar gruplanır; virgül ve kuruşlar yazma sırasında kaybolmaz',()=>{
 for(const [raw,display] of [['30611,22','30.611,22'],['30611','30.611'],['30611,','30.611,'],['30611,2','30.611,2'],['30611.22','30.611,22'],['30.611,22₺','30.611,22'],['0,01','0,01'],['0','0']])assert.equal(formatMoneyEntry(raw),display);
 assert.equal(formatMoneyEntry('30611',{final:true}),'30.611,00');assert.equal(formatMoneyEntry('30611,2',{final:true}),'30.611,20');
 assert.equal(formatMoneyEntry('-30611,22',{negative:true}),'-30.611,22');assert.equal(formatMoneyEntry('12,5',{rate:true}),'12,5');
 for(const raw of ['12,345','1.23.45','-1','1e3','abc']){assert.equal(formatMoneyEntry(raw),raw);assert.throws(()=>parseMoneyAmount(raw));}
});

test('Görüntülenen tüm tutarlar kuruşa dönüşte aynı kalır; üst sınır, sıfır ve negatif düzeltme korunur',()=>{
 const samples=[0,1,10,29,100,101,3061122,99999999999,100000000000,-1,-3061122];
 for(let i=1;i<250;i++)samples.push(i*199997);
 for(const cents of samples)assert.equal(parseMoneyAmount(displayMoneyAmount(cents),{negative:true}),cents);
 assert.equal(parseMoneyAmount('',{optional:true}),null);assert.throws(()=>parseMoneyAmount('1.000.000.000,01'));
 assert.throws(()=>parseMoneyAmount('100,01',{rate:true}));
});

test('Sevkiyat taslağı gruplanmış tutarı mevcut sunucu biçimiyle saklar',()=>{
 const record={invoiceAmount:canonicalMoneyEntry('30.611,22'),orderedAt:'2026-10-30T15:30:00'};
 assert.equal(record.invoiceAmount,'30611.22');assert.equal(canonicalMoneyEntry(''),'');
 const rows=shipmentDraftRows({data:{items:[{id:1}]},draftChanges:[{change:{type:'update',id:1,record}}]});
 assert.equal(rows[0].invoiceCents,3061122);
});
