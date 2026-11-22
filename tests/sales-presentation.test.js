import test from 'node:test';
import assert from 'node:assert/strict';
import {marketplaceSummary,summaryBody} from '../public/marketplace-summary.js';
import {summaryChart} from '../public/sales-summary-chart.js';
import {salesChart} from '../public/report-chart.js';
const period={available:true,from:'2026-10-01',to:'2026-10-24',turnoverCents:13545678,salesCents:12345678,panelSalesCents:13545678,amountCents:11345678,cancelCents:100000,returnCents:1000000,platformDiscountCents:600000,sellerDiscountCents:600000,salesQuantity:4,quantity:3};
const item={channel:'n11',available:true,displayBasis:'sales_before_returns',panelBasis:'before_discounts',refundsVerified:true,periods:{today:period,seven:period,thirty:period,month:period},asOf:'2026-10-24T12:00:00Z',days:[]};

test('Kapalı ayrıntı tüm dört dönemin iptaller hariç tutarlarını içerir; kaynak ve adet değişmez',()=>{
 const before=JSON.stringify(item),html=summaryBody(item,'amount','2026-10');
 assert.equal((html.match(/<strong>₺134\.456,78<\/strong>/g)||[]).length,4);
 assert.equal((html.match(/<dt>İptaller sonrası ciro<\/dt><dd>₺134\.456,78/g)||[]).length,4);
 assert.equal((html.match(/<dt>İndirim<\/dt><dd>₺12\.000,00/g)||[]).length,4);
 assert.match(html,/<details class="summary-details" data-summary-details >/);
 assert.equal((summaryBody(item,'quantity','2026-10').match(/<strong>4 adet/g)||[]).length,4);
 assert.equal(JSON.stringify(item),before);
});
test('Ayrıntı açma tercihi müşteri ve pazaryeri sınırını geçmez',()=>{
 const state={company:1,month:'2026-10',data:{marketplaceSales:{channels:[item]},sales:[]},salesSummaryDetails:{company:1,values:{n11:true}}};
 assert.match(marketplaceSummary(state,'n11'),/data-summary-details open/);
 assert.doesNotMatch(marketplaceSummary({...state,company:2},'n11'),/data-summary-details open/);
 assert.doesNotMatch(marketplaceSummary(state,'Hepsiburada'),/data-summary-details open/);
});
test('Negatif net değer mini grafiğin dışına taşmaz; eksik gün çizgiyle birleştirilmez',()=>{
 const html=summaryChart([{date:'2026-10-01',amountCents:-500},{date:'2026-10-02',amountCents:null},{date:'2026-10-03',amountCents:1000}]);
 const ys=[...html.matchAll(/cy="([\d.-]+)"/g)].map(match=>Number(match[1]));
 assert.equal(ys.length,2);assert(ys.every(value=>value>=16&&value<=112));
 assert.equal((html.match(/class="summary-line"/g)||[]).length,2);
});
test('Mobil ve masaüstü grafik aynı noktaları gösterir; eksik veri ve filtrelenen kanal korunur',()=>{
 const series=[{channel:'n11',available:true,days:[{date:'2026-10-01',netCents:-10000},{date:'2026-10-02',netCents:null},{date:'2026-10-03',netCents:25000}]},{channel:'PTT AVM',available:true,days:[{date:'2026-10-01',netCents:99999}]}];
 const before=JSON.stringify(series),html=salesChart(series,['PTT AVM']);
 assert.match(html,/sales-trend--desktop/);assert.match(html,/sales-trend--mobile/);
 assert.equal((html.match(/n11 · 01.10.2026 · -₺100,00/g)||[]).length,2);
 assert.equal((html.match(/n11 · 03.10.2026 · ₺250,00/g)||[]).length,2);
 assert.equal((html.match(/class="chart-data-point"/g)||[]).length,4);
 assert.doesNotMatch(html,/Günlük tutarları tablo olarak göster|PTT AVM|NaN|Infinity/);
 assert.equal(JSON.stringify(series),before);
});
