import test from 'node:test';
import assert from 'node:assert/strict';
import {analysisResult} from '../public/report-view.js';
import {inventorySalesDetails} from '../public/inventory-sales-details.js';
import {inventoryStockForm} from '../public/inventory-stock-form.js';
import {salesChart} from '../public/report-chart.js';
import {inventoryPermissionAvailable} from '../public/inventory-permission-model.js';
import {overviewSectionOptions} from '../public/overview-sections.js';
const totals={turnoverCents:10000,turnoverAverageCents:5000,panelSalesCents:10000,netCents:8500,orders:2,averageCents:4250,cancelCents:1000,returnCents:500};
const report={from:'2026-11-01',to:'2026-11-04',previousFrom:'2026-10-01',previousTo:'2026-10-04',totals,previousTotals:totals,notes:[],products:[],series:[],availableChannels:0,selectedChannels:0,comparisonAvailable:true};
test('Analiz ciroyu iki role, kesinti göstergelerini Omnigo’ya sunar',()=>{
 for(const admin of [false,true]){
  const content=analysisResult({...report,sensitiveVisibility:{cancellations:admin,returns:admin}});
  assert.match(content,/100,00/);
  assert.equal(content.includes('İptaller Sonrası Ciro'),admin);
  assert.equal(content.includes('İptal & İade'),admin);
 }
});
test('Müşteri stok ayrıntısı ve satış formu gizli iade seçeneği üretmez',()=>{
 const item={key:'a'.repeat(64),title:'Örnek ürün',quantity:5,storeSales:2,sensitiveVisibility:{cancellations:false,returns:false},markets:[{provider:'n11',gross:3,net:2,cancelled:1,returned:0}]};
 const content=inventorySalesDetails(item);assert.doesNotMatch(content,/<th>İptal<\/th>|<th>İade<\/th>/);
 assert.doesNotMatch(inventoryStockForm(item,true),/value="store_return"/);
 assert.match(inventoryStockForm({...item,sensitiveVisibility:{cancellations:true,returns:true}},true),/value="store_return"/);
});
test('Satış grafiği günlük ciro serisini ve aynı tutarı gösterir',()=>{
 const series=[{channel:'n11',available:true,days:[{date:'2026-11-01',turnoverCents:10000,netCents:8500,cancelCents:1000,returnCents:500}]}];
 assert.match(salesChart(series),/01\.11\.2026 · ₺100,00/);
 assert.doesNotMatch(salesChart(series),/report-deductions|₺85,00/);
});
test('Stok modeli uyumsuz izinleri ve müşteri operasyon sekmesini sunmaz',()=>{
 assert.equal(inventoryPermissionAvailable('marketplace','editStock'),false);
 assert.equal(inventoryPermissionAvailable('marketplace','recordStoreSale'),false);
 assert.equal(inventoryPermissionAvailable('store','viewMarketplaceStocks'),false);
 assert.equal(inventoryPermissionAvailable('horoz','recordStoreSale'),true);
 assert.equal(Object.hasOwn(overviewSectionOptions({user:{role:'client'}}),'attention'),false);
 assert.equal(Object.hasOwn(overviewSectionOptions({user:{role:'admin'}}),'attention'),true);
});
