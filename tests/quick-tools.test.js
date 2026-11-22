import test from 'node:test';
import assert from 'node:assert/strict';
import {calculateExpression as calculate} from '../public/calculator-model.js';
import {calendarDays} from '../public/period-picker.js';
test('Hesap makinesi: Türkçe ondalık, işlem sırası, yüzde, negatif ve geçersiz giriş',()=>{
 assert.equal(calculate('1,5 + 2,75'),4.25);assert.equal(calculate('1200 × 20%'),240);assert.equal(calculate('(100+20)/4'),30);assert.equal(calculate('-5*3'),-15);assert.equal(calculate('0.1+0.2'),.3);
 for(const value of ['1/0','1+','(2+3','alert(1)','2**3','9'.repeat(121)])assert.throws(()=>calculate(value));
});
test('Takvim: Pazartesi başlangıcı, artık yıl ve yıl sınırları',()=>{
 const feb=calendarDays('2024-02');assert.equal(feb.length,42);assert.equal(feb[0].date,'2024-01-29');assert.equal(feb.filter(day=>day.current).length,29);
 assert.equal(calendarDays('2026-02').filter(day=>day.current).length,28);assert.equal(calendarDays('2026-01')[0].date,'2025-12-29');
});
