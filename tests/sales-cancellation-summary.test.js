
test('customer dashboard, daily graph, sales, analysis, PDF and Excel source share a single adjusted turnover',()=>{
 const row={channel:'PTT AVM',month:'2026-11',turnoverDisplayBasis:'after_cancellations',turnoverCents:50000,grossCents:50000,orders:1,soldUnits:2};
 for(const grant of [false,true]){
  const visible=grant?{...row,cancelledTurnoverCents:50000,returnCents:1000}:row;
  const sensitiveVisibility={turnover:true,cancellations:grant,returns:grant,afterCancellationTurnover:grant};
  const data={turnoverDisplayBasis:'after_cancellations',channels:[{name:'PTT AVM',status:'active'}],sales:[visible],payments:[],summary:{...visible,paidCents:0,expectedCents:0},sensitiveVisibility,missingPaymentChannels:[],marketplaceSales:{currentMonthTurnoverCents:50000,channels:[{channel:'PTT AVM',available:true,days:[{date:'2026-11-06',...visible}]}]}};
  assert.match(stats(data,{overview:true}),/Bu ay<\/span><strong>[^<]*500,00/);
  assert.equal(overviewDailyData(data).days[0].amountCents,50000);
  assert.equal(turnoverValue(visible),50000);
  const table=monthlySales({user:{role:'client'},month:'2026-11',data});assert.match(table,/data-label="Ciro"[^>]*><strong>[^<]*500,00/);assert.doesNotMatch(table,/iptal sonrası|iptaller çıkarılır/i);
  const report={...data,company:{name:'Örnek'},from:'2026-11-01',to:'2026-11-06',previousFrom:'2026-10-01',previousTo:'2026-10-06',totals:visible,previousTotals:visible,notes:[],products:[{...visible,title:'Ürün',sku:'S1',netQuantity:2}],series:[],availableChannels:1,selectedChannels:1,comparisonAvailable:true,payments:{items:[]}};
  const html=analysisResult(report),sheets=reportTables(report),top=overviewTopResult(report);
  assert.match(top,/İptal sonrası ciro/);assert.doesNotMatch(top,/İptaller Sonrası Ciro/);
  assert.match(html,/İptal sonrası ciro/);assert.doesNotMatch(html,/İptaller Sonrası Ciro/);
  assert.equal(sheets[0].rows[0][1],500);assert.equal(sheets[0].rows[0][0],'İptal sonrası ciro (TL)');
  assert.equal(sheets[0].rows.filter(r=>/ciro/i.test(r[0])).length,1);
  if(!grant)assert.equal(sheets[0].rows.some(r=>/İptal & İade|İade tutarı/.test(r[0])),false);
 }
});
import test from 'node:test';import assert from 'node:assert/strict';
import {turnoverValue,turnoverTotal,turnoverDays,cancelledTurnoverValue} from '../public/sales-turnover.js';
import {rankBestsellers} from '../public/report-bestsellers.js';
import {overviewTopResult} from '../public/overview-sections.js';
import {stats} from '../public/views.js';import {monthlySales} from '../public/monthly-sales.js';
import {overviewDailyData} from '../public/balanced-overview.js';
import {analysisResult} from '../public/report-view.js';import {reportTables} from '../public/report-export-data.js';
const sale={channel:'PTT AVM',month:'2026-11',turnoverCents:100000,cancelledTurnoverCents:50000,salesTurnoverCents:50000,grossCents:90000,cancelCents:45000,returnCents:1000,netCents:44000,orders:2};
test('cancelled turnover uses the same price basis, survives redaction, and never changes invoices',()=>{
 const before=JSON.stringify(sale);assert.equal(turnoverValue(sale),50000);assert.equal(cancelledTurnoverValue(sale),50000);
 const hidden={...sale};delete hidden.cancelCents;delete hidden.cancelledTurnoverCents;assert.equal(turnoverValue(hidden),50000);
 assert.equal(turnoverValue({...sale,salesTurnoverCents:null}),null);assert.equal(turnoverTotal([sale,{...sale,salesTurnoverCents:0}]),50000);
 assert.equal(turnoverDays([sale])[0].amountCents,50000);assert.equal(JSON.stringify(sale),before);
});
test('overview daily chart and marketplace table share turnover before cancellations',()=>{
 for(const role of ['admin','client']){
  const visible=role==='admin',data={channels:[{name:'PTT AVM',status:'active'}],sales:[sale],payments:[],summary:{...sale,paidCents:25000,expectedCents:30000},sensitiveVisibility:{cancellations:visible,returns:visible},missingPaymentChannels:[],marketplaceSales:{channels:[{channel:'PTT AVM',available:true,days:[{date:'2026-11-06',...sale}]}]}};
  if(!visible){data.turnoverDisplayBasis='before_cancellations';data.marketplaceSales.currentMonthTurnoverCents=100000;}
  assert.match(stats(data,{overview:true}),/1\.000,00/);
  const table=monthlySales({user:{role},month:'2026-11',data},{grossOnly:true});
  assert.match(table,/Aktif ciro[\s\S]*1\.000,00/);assert.doesNotMatch(table,/data-label="İptal"/);
  assert.equal(overviewDailyData(data).days[0].amountCents,100000);
 }
});
test('TOP10 merges whole product rows without re-deducting cancellation or changing net quantity',()=>{
 const rows=rankBestsellers([{...sale,sku:'SKU',title:'Ürün',netQuantity:1},{...sale,sku:'SKU',title:'Ürün',netQuantity:2}]);assert.equal(rows.length,1);assert.equal(rows[0].turnoverCents,200000);assert.equal(turnoverValue(rows[0]),100000);assert.equal(rows[0].netCents,88000);assert.equal(rows[0].netQuantity,3);
});
test('report and exports expose gross turnover to customers and cancellation totals to administrators',()=>{
 const report={company:{name:'Örnek'},from:'2026-11-01',to:'2026-11-06',previousFrom:'2026-10-01',previousTo:'2026-10-06',totals:sale,previousTotals:sale,notes:[],products:[],series:[],availableChannels:1,selectedChannels:1,comparisonAvailable:true,payments:{items:[]}};
 for(const visible of [true,false]){
  const data={...report,sensitiveVisibility:{cancellations:visible,returns:visible}},html=analysisResult(data);
  assert.match(html,/1\.000,00/);assert.equal(html.includes('İptaller Sonrası Ciro'),visible);
  const summary=reportTables(data)[0];assert.equal(summary.rows[0][1],1000);
  assert.equal(summary.rows.some(row=>row[0]==='İptaller Sonrası Ciro (TL)'&&row[1]===500),visible);
 }
});
