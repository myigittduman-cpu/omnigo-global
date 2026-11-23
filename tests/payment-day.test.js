import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentDayItems,paymentDayView} from '../public/payment-day-view.js';
import {paymentCalendar} from '../public/payment-calendar.js';
import {receivedPaymentsView} from '../public/received-payments-view.js';
const report={from:'2026-11-01',to:'2026-11-30',summary:{},items:[
 {id:1,channel:'Hepsiburada',inCalendar:true,calendarDate:'2026-11-02',expectedDate:'2026-11-02',expectedCents:3061122,status:'pending'},
 {id:2,channel:'n11',inCalendar:true,calendarDate:'2026-11-02',expectedDate:'2026-11-03',paidDate:'2026-11-02',expectedCents:2500,actualCents:2400,status:'paid',paymentApproval:{at:'2026-11-07T00:00:00Z'}},
 {id:3,inCalendar:true,calendarDate:'2026-11-02',excludedFromTotals:true},
 {id:4,inCalendar:false,calendarDate:'2026-11-02'},
 {id:5,inCalendar:true,calendarDate:'2026-11-05'}
]};
test('Gün detayı yalnız o güne ait bekleyen kayıtları gösterir; hesaba geçen ayrı listede kalır',()=>{
 const rows=paymentDayItems(report,'2026-11-02');assert.deepEqual(rows.map(item=>item.id),[1]);
 const html=paymentDayView(rows,true);assert.match(html,/30\.611,22/);assert.doesNotMatch(html,/Hesaba geçiş/);
 assert.equal((html.match(/data-day-payment-edit=/g)||[]).length,1);assert.equal((html.match(/data-day-payment-receipt=/g)||[]).length,1);assert.match(html,/Ödeme bilgisi gir/);assert.doesNotMatch(html,/Ödeme bilgisini düzenle/);assert.match(html,/data-day-payment-add/);
});
test('Müşteri görünümünde ekleme ve düzenleme yok; eski otomatik kayıt düzenlenmez',()=>{
 assert.doesNotMatch(paymentDayView(paymentDayItems(report,'2026-11-02'),false),/data-day-payment-(add|edit|receipt)/);
 assert.doesNotMatch(paymentDayView([{...report.items[0],integration:'legacy'}],true),/data-day-payment-(edit|receipt)/);
});
test('Boş takvim gününde yöneticiye ekleme, dolu günde detay eylemi gösterilir',()=>{
 const data={...report,items:report.items.slice(0,2)};
 const admin=paymentCalendar(data,'',true),client=paymentCalendar(data,'',false);
 assert.match(admin,/<h3>Kasım 2026<\/h3>/);assert.match(admin,/1 Kasım 2026 · Hakediş ekle/);
 assert.match(admin,/2 Kasım 2026 · 1 ödeme kaydı ·[^"<]+Detayları aç/);
 assert.doesNotMatch(client,/calendar-day-add|Hakediş ekle/);
});

test('Canlı ve manuel gün kayıtları yöneticiye kendi silme düğmesiyle görünür',()=>{
 const manual={...report.items[0],channel:'idefix',version:3};
 const day=manual.expectedDate;
 const forecast={id:'hepsiburada:'+day,channel:'Hepsiburada',date:day,amountCents:9200,
  verification:'estimated',source:'marketplace',adjustmentRevision:'revision'};
 const data={...report,items:[manual],upcoming:{today:day,periodItems:[
  forecast,{id:manual.id,channel:manual.channel,date:day,amountCents:manual.expectedCents,source:'manual'},
 ]}};
 const rows=paymentDayItems(data,day);
 assert.equal(rows.length,2);
 assert.equal(rows.find(row=>row.id===forecast.id).adjustmentRevision,forecast.adjustmentRevision);
 assert.match(paymentDayView(rows,true),/data-day-payment-remove="hepsiburada:2026-11-02"/);
 assert.match(paymentDayView(rows,true),/data-day-payment-remove="1"/);
 assert.match(receivedPaymentsView(data,1,true,'calendar'),/data-received-remove="hepsiburada:2026-11-02"/);
 assert.doesNotMatch(paymentDayView(rows,false),/data-day-payment-remove/);
 assert.doesNotMatch(receivedPaymentsView(data,1,false,'calendar'),/data-received-remove/);
 assert.doesNotMatch(paymentDayView([{...rows[0],adjustmentRevision:undefined},{...manual,paymentApproval:{at:'done'}}],true),/data-day-payment-remove/);
});
