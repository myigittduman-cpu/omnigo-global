import test from 'node:test';
import assert from 'node:assert/strict';
import {shiftMonth} from '../public/period-picker.js';
test('Dönem geçişleri yıl sınırını ve Şubat ayını doğru işler',()=>{
 assert.equal(shiftMonth('2026-12',1),'2027-01');
 assert.equal(shiftMonth('2026-01',-1),'2025-12');
 assert.equal(shiftMonth('2024-03',-1),'2024-02');
 assert.throws(()=>shiftMonth('2026-13',1));
});
