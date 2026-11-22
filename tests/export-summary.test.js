import test from 'node:test';
import assert from 'node:assert/strict';
import {deliverySummary,shipmentSummary,summaryText} from '../public/export-summary.js';
test('aynı alıcının iki siparişi ayrı, aynı siparişin paketleri tek sipariş sayılır; adetler toplanır',()=>{
 const result=deliverySummary([
  {provider:'n11',orderNumber:'10',recipientName:'Örnek',products:[{quantity:2},{quantity:1}]},
  {provider:'n11',orderNumber:'10',recipientName:'Örnek',products:[{quantity:3}]},
  {provider:'n11',orderNumber:'11',recipientName:'Örnek',products:[{quantity:1}]},
  {provider:'pttavm',orderNumber:'10',recipientName:'Örnek',products:[{quantity:2}]}
 ]);
 assert.deepEqual(result,{orderCount:3,productCount:9});
 assert.equal(summaryText(result),'Toplam: 3 sipariş · 9 parça ürün');
 assert.deepEqual(shipmentSummary({items:[],summary:result}),result);
 assert.deepEqual(deliverySummary([]),{orderCount:0,productCount:0});
});
