import test from 'node:test';
import assert from 'node:assert/strict';
import {parseManualDate,displayManualDate} from '../public/manual-date-filters.js';
import {paymentRangeMonths,paymentRangeGrid} from '../public/payment-range-grid.js';
import {workspaceRange,dashboardQuery,workspaceDateRange} from '../public/workspace-date-range.js';
import {now} from '../public/ui.js';
import {defaultWorkspaceRange} from '../public/filter-date-range.js';

test('İki satış girişinde otomatik aralık günü izler, elle seçilen aralık korunur',()=>{
 for(const view of ['overview','channels']){
  const first=defaultWorkspaceRange(null,7,view,'2026-10-29');
  assert.deepEqual(first,{company:7,from:'2026-10-01',to:'2026-10-29',automatic:true});
  assert.equal(defaultWorkspaceRange(first,7,view,'2026-10-30').to,'2026-10-30');
  assert.equal(defaultWorkspaceRange(first,7,view,'2026-11-01').from,'2026-11-01');
  const custom={company:7,from:'2026-09-01',to:'2026-09-15',automatic:false};
  assert.deepEqual(defaultWorkspaceRange(custom,7,view,'2026-10-30'),custom);
  assert.equal(defaultWorkspaceRange(custom,8,view,'2026-10-30').from,'2026-10-01');
 }
});
test('Elle tarih girişi gerçek günleri doğrular ve ISO sözleşmesini korur',()=>{
 assert.equal(parseManualDate('1.2.2026'),'2026-02-01');
 assert.equal(parseManualDate('29.02.2024'),'2024-02-29');
 assert.equal(parseManualDate('2026-10-28'),'2026-10-28');
 for(const value of ['31.02.2026','29.02.2026','00.10.2026','28.13.2026','2026-10','abc'])assert.equal(parseManualDate(value),'');
 assert.equal(displayManualDate('2026-10-28'),'28.10.2026');
});
test('Genel bakış ay başından bugüne başlar; aralık müşteri değiştirilince taşınmaz',()=>{
 const state={company:1,month:'2026-10',view:'channels'};
 assert.deepEqual(workspaceRange(state),{company:1,from:now().slice(0,7)+'-01',to:now(),automatic:true});
 state.dateRange={company:1,from:'2026-09-30',to:'2026-10-02'};
 assert.match(dashboardQuery(state),/from=2026-09-30&to=2026-10-02/);
 assert.match(workspaceDateRange(state),/Başlangıç tarihi/);
 state.company=2;assert.equal(workspaceRange(state).from,now().slice(0,7)+'-01');
 state.view='shipments';assert.equal(workspaceDateRange(state),'');
});
test('Hakediş takviminde ay ve yıl sınırları gün atlamaz',()=>{
 const months=paymentRangeMonths('2026-12-30','2027-01-02');
 assert.deepEqual(months.map(m=>m.days),[['2026-12-30','2026-12-31'],['2027-01-01','2027-01-02']]);
 assert.equal(paymentRangeMonths('2024-02-28','2024-03-01').flatMap(m=>m.days).length,3);
 const html=paymentRangeGrid({from:'2026-10-28',to:'2026-10-28'},'',[{calendarDate:'2026-10-28',status:'pending',expectedCents:12345}]);
 assert.match(html,/data-payment-day="2026-10-28"/);assert.match(html,/123,45/);assert.doesNotMatch(html,/data-payment-day="2026-10-01"/);
});
