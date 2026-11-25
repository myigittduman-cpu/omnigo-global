import test from 'node:test';
import assert from 'node:assert/strict';
import {tableHeightBudget} from '../public/table-panel-fit.js';

test('Tablo alanı başlıkları, sayfa düğmelerini ve alt alanı hesaba katar',()=>{
 assert.equal(tableHeightBudget(950,200,140,105),505);
 assert.equal(tableHeightBudget(720,200,140,105),275);
});

test('Çok kısa pencerede tabloya erişim için asgari yükseklik korunur',()=>{
 assert.equal(tableHeightBudget(420,260,120,70),150);
});
