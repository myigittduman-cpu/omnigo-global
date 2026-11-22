import test from 'node:test';
import assert from 'node:assert/strict';
import {summaryBody} from '../public/marketplace-summary.js';
test('n11 kapsayıcı tarihleri, satış ve iptal/iade dökümünü açık gösterir',()=>{
 const p={available:true,from:'2026-10-10',to:'2026-10-17',salesCents:27488400,amountCents:27000000,cancelCents:18585600,returnCents:488400};
 const html=summaryBody({available:true,periodPolicy:'n11_inclusive',displayBasis:'sales_before_returns',periods:{seven:p,thirty:{...p,from:'2026-09-17',salesCents:116425500}},asOf:'2026-10-17T19:31:02+03:00'},'amount','2026-10');
 for(const value of ['Son 1 haftalık satış','Son 1 aylık satış','274.884,00','1.164.255,00','185.856,00','4.884,00','başlangıç günü ve bugün dahildir'])assert.ok(html.includes(value),value);
 assert.doesNotMatch(html,/Son 7 gün|Son 30 gün|NaN|undefined/);
});
test('Diğer pazaryerlerinde standart 7 ve 30 günlük metin korunur',()=>{
 const html=summaryBody({available:true,displayBasis:'sales_before_returns',periods:{},asOf:'2026-10-17T19:31:02+03:00'},'amount','2026-10');
 assert.match(html,/Son 7 gün/);assert.match(html,/Son 30 gün/);assert.doesNotMatch(html,/n11 panelindeki/);
});
