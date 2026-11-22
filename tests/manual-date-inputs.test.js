import test from 'node:test';
import assert from 'node:assert/strict';
import {parseDateEntry,displayDateEntry,dateEntryError} from '../public/manual-date-value.js';
import {periodPicker} from '../public/period-picker.js';
import {shipmentMonthFilter} from '../public/shipments-view.js';

test('Gün, ay ve saatli tarihler Türkçe ve ISO biçimlerini kayıpsız çevirir',()=>{
 for(const [mode,typed,iso] of [
  ['date','29.02.2024','2024-02-29'],['date','1/2/2026','2026-02-01'],['date','30102026','2026-10-30'],
  ['month','10.2026','2026-10'],['month','102026','2026-10'],
  ['datetime-local','30.10.2026 9:15','2026-10-30T09:15'],['datetime-local','30102026153045','2026-10-30T15:30:45']
 ]){assert.equal(parseDateEntry(typed,mode),iso);assert.equal(parseDateEntry(iso,mode),iso);assert.equal(parseDateEntry(displayDateEntry(iso,mode),mode),iso);}
});
test('Geçersiz gün, saat, eksik giriş ve sınır dışı tarihler engellenir',()=>{
 for(const value of ['31.02.2026','29.02.2026','30.13.2026','30.10.26','abc'])assert.equal(parseDateEntry(value),'');
 for(const value of ['13.2026','00.2026','2026'])assert.equal(parseDateEntry(value,'month'),'');
 for(const value of ['30.10.2026 24:00','30.10.2026 15:60','30.10.2026'])assert.equal(parseDateEntry(value,'datetime-local'),'');
 assert.equal(dateEntryError('',{}),'');assert.ok(dateEntryError('',{required:true}));
 assert.ok(dateEntryError('01.10.2026',{min:'2026-10-02'}));
 assert.ok(dateEntryError('30.10.2026 12:00',{mode:'datetime-local',max:'2026-10-30T11:59:59'}));
 assert.equal(dateEntryError('30.10.2026 11:59:59',{mode:'datetime-local',max:'2026-10-30T11:59:59'}),'');
});
test('Tarih girişi manueldir; sevkiyat ay filtresi istenen ay adlarını sunar',()=>{
 assert.doesNotMatch(periodPicker('2026-10'),/<button|aria-haspopup|Takvimi aç/);
 assert.match(shipmentMonthFilter({month:'2026-10'}),/<select[^>]+data-shipment-month-filter/);assert.match(shipmentMonthFilter({month:'2026-10'}),/>Ekim<\/option>/);
 assert.equal(periodPicker('2026-10',true),'');
});
