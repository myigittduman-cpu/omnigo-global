import test from 'node:test';
import assert from 'node:assert/strict';
import {salesChange,summaryBody,summaryChart} from '../public/marketplace-summary.js';
import {summarySeries} from '../public/sales-summary-chart.js';
import {deductionDays} from '../public/report-chart.js';
test('Sıfır veya eksik karşılaştırma sonsuz yüzde üretmez',()=>{
 assert.equal(salesChange(100,0),null);assert.equal(salesChange(0,0),0);assert.equal(salesChange(100,null),null);assert.equal(salesChange(125,100),25);
});

test('Ciro ile dönem, iptal ve iade ayrıntısı bağımsız görünür',()=>{
 const period={available:true,from:'2026-10-10',to:'2026-10-16',turnoverCents:15000,salesCents:14000,amountCents:12000,cancelCents:1000,returnCents:2000,salesQuantity:4,quantity:3};
 const item={available:true,displayBasis:'sales_before_returns',completedPeriods:true,periods:{seven:period},days:[],asOf:'2026-10-17T17:00:00+03:00'};
 const html=summaryBody(item,'amount','2026-10');assert.match(html,/<strong>₺140,00/);assert.match(html,/<dt>İptal<\/dt><dd>₺10,00/);assert.match(html,/<dt>İade<\/dt><dd>₺20,00/);assert.match(html,/<dt>İptaller sonrası ciro<\/dt><dd>₺140,00/);assert.match(html,/10 Eki.*16 Eki/);assert.match(html,/Satış özeti/);
 assert.match(summaryBody(item,'quantity','2026-10'),/4 adet/);
 assert.deepEqual(summarySeries('amount',item).map(s=>s.field),['salesCents','amountCents','cancelCents','returnCents']);
 assert(!summarySeries('amount',{refundsVerified:false}).some(s=>s.field==='returnCents'));
});
test('Rapor iptal ve iade çizgileri ayrı toplanır; eksik iade sıfır sayılmaz',()=>{
 const days=deductionDays([{days:[{date:'2026-09-01',cancelCents:100,returnCents:200}]},{days:[{date:'2026-09-01',cancelCents:300,returnCents:null}]}]);
 assert.deepEqual(days,[{date:'2026-09-01',cancelCents:400,returnCents:null}]);
 const html=summaryChart(days,'amount',{deductionsOnly:true,chartLabel:'Seçili ay'});assert.match(html,/İptal/);assert.match(html,/İade/);assert.match(html,/Seçili ay/);assert.doesNotMatch(html,/NaN|Infinity/);
});
test('Tutar ve adet ayrı değerleri gösterir; iade kapsamı kaybolmaz',()=>{
 const period={available:true,amountCents:123456,quantity:3,orders:2,previous:null};
 const item={available:true,refundsVerified:false,periods:{today:period,seven:period,thirty:period,month:period},days:[],asOf:'2026-10-11T16:00:00+03:00'};
 assert.match(summaryBody(item,'quantity','2026-10'),/3 adet/);assert.match(summaryBody(item,'amount','2026-10'),/1.234,56/);
 assert.match(summaryBody(item,'amount','2026-10'),/İade verisi henüz aktarılmadı/);assert.doesNotMatch(summaryBody(item,'amount','2026-10'),/Infinity|NaN/);
});
test('Eksik grafik günü çizgi ile bağlanmaz ve kaynak metni güvenlidir',()=>{
 const html=summaryChart([{date:'2026-10-01',amountCents:100},{date:'2026-10-02',amountCents:null},{date:'2026-10-03',amountCents:200}]);
 assert.equal((html.match(/class="summary-line"/g)||[]).length,2);
 assert.match(summaryBody({available:false,message:'<img onerror=x>'},'amount','2026-10'),/&lt;img/);
});

