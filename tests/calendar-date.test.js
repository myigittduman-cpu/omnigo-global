import test from 'node:test';
import assert from 'node:assert/strict';
import {calendarDays,shiftMonth,shiftCalendarDay,validCalendarDate,calendarValueAllowed} from '../public/calendar-date.js';
test('Takvim artık yıl, ay sınırı ve Pazartesi başlangıcını doğru hesaplar',()=>{
 assert.equal(validCalendarDate('2024-02-29'),true);assert.equal(validCalendarDate('2026-02-29'),false);assert.equal(validCalendarDate('2026-04-31'),false);
 assert.equal(shiftMonth('2026-12',1),'2027-01');assert.equal(shiftCalendarDay('2024-02-28',1),'2024-02-29');assert.equal(shiftCalendarDay('2026-03-01',-1),'2026-02-28');
 const days=calendarDays('2026-10');assert.equal(days.length,42);assert.equal(new Date(days[0].date+'T12:00Z').getUTCDay(),1);
});
test('Tarih, dönem ve saat sınırları korunur; zorunlu alan temizlenemez',()=>{
 assert.equal(calendarValueAllowed('',{required:true}),false);assert.equal(calendarValueAllowed(''),true);
 assert.equal(calendarValueAllowed('2026-10-20',{min:'2026-10-20',max:'2026-10-20'}),true);
 assert.equal(calendarValueAllowed('2026-10-19',{min:'2026-10-20'}),false);
 assert.equal(calendarValueAllowed('2026-10-20junk'),false);
 assert.equal(calendarValueAllowed('2026-10',{mode:'month',min:'2026-09',max:'2026-10'}),true);
 assert.equal(calendarValueAllowed('2026-11',{mode:'month',max:'2026-10'}),false);
 assert.equal(calendarValueAllowed('2026-10-20T09:00',{mode:'datetime-local',min:'2026-10-20T09:00:00'}),true);
 assert.equal(calendarValueAllowed('2026-10-20T09:00:01',{mode:'datetime-local',max:'2026-10-20T09:00'}),false);
 assert.equal(calendarValueAllowed('2026-10-20T24:00',{mode:'datetime-local'}),false);
});
