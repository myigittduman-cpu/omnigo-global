import assert from 'node:assert/strict';
import test from 'node:test';
import {selectUpcomingPaymentPeriod,upcomingPaymentPeriods} from '../public/upcoming-payment-periods.js';

const payment=(date,channel='n11',amountCents=100)=>({date,channel,amountCents});
const items=[
 payment('2026-12-28'),payment('2026-11-03'),payment('2026-11-23','idefix'),
 payment('2026-11-12'),payment('2026-11-01'),payment('2026-11-12','idefix'),
 payment('2026-12-07'),payment('2026-11-15','Hepsiburada'),payment('2026-12-05'),
];
const starts=periods=>periods.map(period=>period.start);

test('15 günlük dönem ilk ödeme gününden başlar ve sonraki dönem gerçek ödeme tarihini kullanır',()=>{
 const periods=upcomingPaymentPeriods(items,'2026-11-10');
 assert.deepEqual(starts(periods),['2026-11-01','2026-11-23','2026-12-28']);
 assert.deepEqual(periods.map(period=>period.end),['2026-11-15','2026-12-07','2027-01-11']);
 assert.deepEqual(periods[0].items.map(item=>item.date),[
  '2026-11-01','2026-11-03','2026-11-12','2026-11-12','2026-11-15',
 ]);
 assert.equal(periods[1].lastPaymentDay,'2026-12-07');
 assert.equal(items[0].date,'2026-12-28');
});

test('Sıradaki dönemin ilk ödeme gününde güncel sayfa değişir ve eski dönemler yeniden sıralanır',()=>{
 const withoutFinalDay=items.filter(item=>item.date!=='2026-11-15');
 assert.deepEqual(starts(upcomingPaymentPeriods(withoutFinalDay,'2026-11-12')),[
  '2026-11-01','2026-11-23','2026-12-28',
 ]);
 assert.deepEqual(starts(upcomingPaymentPeriods(withoutFinalDay,'2026-11-13')),[
  '2026-11-01','2026-11-23','2026-12-28',
 ]);
 assert.deepEqual(starts(upcomingPaymentPeriods(withoutFinalDay,'2026-11-22')),[
  '2026-11-01','2026-11-23','2026-12-28',
 ]);
 const rolled=upcomingPaymentPeriods(withoutFinalDay,'2026-11-23');
 assert.deepEqual(starts(rolled),['2026-11-23','2026-11-01','2026-12-28']);
 assert.deepEqual(selectUpcomingPaymentPeriod(rolled,'2026-11-01'),{period:rolled[1],page:2,pages:3});
 assert.deepEqual(starts(upcomingPaymentPeriods(items,'2026-12-28')),[
  '2026-12-28','2026-11-23','2026-11-01',
 ]);
 assert.deepEqual(starts(upcomingPaymentPeriods(items,'2027-01-12')),[
  '2026-12-28','2026-11-23','2026-11-01',
 ]);
});

test('Gizli pazaryeri ve geçersiz tarih dönem oluşturmaz, bilinmeyen seçim güncel döneme döner',()=>{
 const periods=upcomingPaymentPeriods([
  payment('2026-11-02','Teknosa'),payment('2026-11-31'),payment('2026-11-23'),null,
 ],'2026-11-01');
 assert.deepEqual(starts(periods),['2026-11-23']);
 assert.deepEqual(selectUpcomingPaymentPeriod(periods,'2026-11-03'),{period:periods[0],page:1,pages:1});
 assert.deepEqual(selectUpcomingPaymentPeriod([]),{period:null,page:0,pages:0});
});
