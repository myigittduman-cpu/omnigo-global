import test from 'node:test';import assert from 'node:assert/strict';
import {summaryBody} from '../public/marketplace-summary.js';import {orderPricing,orderPricingView} from '../public/order-pricing.js';import {summarySeries} from '../public/sales-summary-chart.js';
test('Pazarama cirosu alıcı ödemesi yerine kaynak brüt tutarı gösterir',()=>{
 const p={available:true,turnoverCents:4234500,panelSalesCents:4021775,salesCents:4234500,amountCents:4234500,platformDiscountCents:212725,sellerDiscountCents:20000,cancelCents:0,returnCents:0};
 const html=summaryBody({available:true,displayBasis:'sales_before_returns',panelBasis:'buyer_payment',refundsVerified:true,periods:{today:p,seven:p,thirty:p},asOf:'2026-10-17T18:00:00+03:00'},'amount','2026-10');
 for(const v of ['42.345,00','2.127,25','Pazaryeri katkısı','İptaller sonrası ciro'])assert.ok(html.includes(v),v);
 assert.doesNotMatch(html,/NaN|undefined/);
});
test('Pazarama indirim dökümü fatura tutarını değiştirmez ve gizlenmez',()=>{
 const pricing={originalCents:4254500,platformDiscountCents:212725,sellerDiscountCents:20000,discountedCents:4021775,reportCents:4234500,reportBasis:'seller_invoice'};
 const order={grossCents:4234500,products:[{grossCents:4234500,pricing}]};
 assert.equal(orderPricing(order).reportCents,4234500);const html=orderPricingView(order);
 assert.match(html,/Fatura tutarı/);assert.match(html,/40.217,75/);assert.doesNotMatch(html,/<details|<button/);
});
test('idefix grafiği panel tutarını, iadeyi ve fatura netini ayrı kullanır',()=>{
 const series=summarySeries('amount',{displayBasis:'sales_before_returns',panelBasis:'before_discounts'});
 assert.equal(series[0].field,'panelSalesCents');assert.ok(series.some(s=>s.field==='returnCents'));assert.ok(series.some(s=>s.field==='amountCents'));
});
