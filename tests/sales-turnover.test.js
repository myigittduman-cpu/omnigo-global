import test from 'node:test';import assert from 'node:assert/strict';
import {turnoverValue,turnoverTotal,turnoverMoney} from '../public/sales-turnover.js';
import {stats} from '../public/views.js';import {monthlySales} from '../public/monthly-sales.js';
import {rankBestsellers} from '../public/report-bestsellers.js';
test('turnover is independent from invoice net and unknown amounts remain unknown',()=>{
 const value={turnoverCents:105000,grossCents:100000,netCents:70000};assert.equal(turnoverValue(value),105000);assert.equal(turnoverValue({turnoverCents:null,grossCents:100000}),null);assert.equal(turnoverTotal([value,{turnoverCents:null}]),null);assert.match(turnoverMoney({turnoverCents:null}),/Bekleniyor/);assert.equal(value.netCents,70000);
});
test('customer monthly card and sales table both use verified turnover after cancellations',()=>{
 const sale={channel:'n11',month:'2026-11',grossCents:100000,cancelCents:20000,returnCents:10000,netCents:70000,turnoverCents:105000,orders:1};
 const data={channels:[{name:'n11',status:'active'}],sales:[sale],payments:[],summary:{...sale,paidCents:20000,expectedCents:30000},sensitiveVisibility:{cancellations:true,returns:true},missingPaymentChannels:[]};
 const table=monthlySales({user:{role:'admin'},month:'2026-11',data});assert.match(table,/İptaller sonrası ciro/);assert.match(table,/850,00/);
 const customer={...sale,turnoverCents:85000,salesTurnoverCents:85000,turnoverDisplayBasis:'after_cancellations'};delete customer.cancelCents;
 assert.equal(turnoverValue(customer),85000);assert.equal(customer.netCents,70000);
 const customerData={...data,turnoverDisplayBasis:'after_cancellations',sales:[customer],summary:customer,sensitiveVisibility:{cancellations:false,returns:false},marketplaceSales:{currentMonthTurnoverCents:85000}};
 const customerTable=monthlySales({user:{role:'client'},month:'2026-11',data:customerData});
 assert.match(customerTable,/Ciro.*850,00/);assert.doesNotMatch(customerTable,/1\.050,00|İptal tutarı|iptal sonrası|iptaller çıkarılır/i);
 assert.match(stats(customerData),/Bu ay<\/span><strong>.*850,00/);assert.doesNotMatch(stats(customerData),/İptal tutarı|İptaller sonrası|1\.050,00/);
 assert.doesNotMatch(stats({...customerData,workspaceAccess:{storeRole:'accounting_logistics'}}),/850,00|iptal sonrası ciro/);
 assert.match(stats(data),/Bu Ay/);assert.match(stats(data),/850,00/);
});
test('same SKU aggregates turnover without swapping invoice fields',()=>{
 const ranked=rankBestsellers([{sku:'SKU',title:'Ürün',channel:'A',netQuantity:2,netCents:10,turnoverCents:20},{sku:'SKU',title:'Ürün',channel:'B',netQuantity:1,netCents:30,turnoverCents:50}]);assert.equal(ranked[0].turnoverCents,70);assert.equal(ranked[0].netCents,40);
});
