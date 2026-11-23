import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentTable} from '../public/views.js';
import {paymentCalendar} from '../public/payment-calendar.js';
import {paymentSummaryCards,refreshPaymentForecastCards} from '../public/payment-summary-cards.js';
import {currentPaymentForecast} from '../public/upcoming-payment-model.js';
import {now} from '../public/ui.js';
test('Tahmini hakediş kartı üst tablodaki dönem toplamını gösterir ve yeni dönemde güncellenir',()=>{
 const report={
  from:'2026-11-24',to:'2026-12-08',channel:'all',marketplaces:['n11','Hepsiburada'],
  items:[],summary:{estimatedCents:800000,confirmedCents:900000,sentCents:30000,receivedCents:40000},
  upcoming:{today:'2026-11-24',items:[
   {date:'2026-11-24',channel:'n11',amountCents:12500,verification:'estimated'},
   {date:'2026-11-25',channel:'Hepsiburada',amountCents:25000,verification:'confirmed'},
   {date:'2026-12-09',channel:'n11',amountCents:60000,verification:'confirmed'},
  ]},
 };
 const html=paymentCalendar(report);
 assert.match(html,/upcoming-payments-total[\s\S]*?<strong>₺375,00<\/strong>/);
 assert.match(html,/<span>Tahmini hakediş<\/span><strong data-payment-forecast-total>₺375,00<\/strong>/);
 assert.doesNotMatch(html,/data-payment-(estimated|confirmed)-total/);
 assert.match(html,/Aktarımı bildirilen/);
 assert.match(html,/Hesaba geçen/);
 const amount={textContent:''};
 const root={querySelector(selector){assert.equal(selector,'[data-payment-forecast-total]');return amount;}};
 refreshPaymentForecastCards(root,currentPaymentForecast(report,'2026-12-09'));
 assert.equal(amount.textContent,'₺600,00');
 assert.match(paymentSummaryCards({estimatedCents:12500,confirmedCents:25000},null),/data-payment-forecast-total>₺375,00</);
});
test('Yaklaşan ödemelerde tarihsiz ödeme ayrı görünür; geçmiş aktarım öne çıkarılmaz',()=>{
 const base={id:1,channel:'Pazarama',expectedDate:now(),expectedCents:6438501,status:'pending',integration:'pazarama'};
 const payments=[base,{...base,id:2,expectedDate:'2000-01-01',channel:'n11',integration:null,status:'sent'},{...base,id:3,expectedDate:'',channel:'Koçtaş'}];
 const html=paymentTable({user:{role:'admin'},data:{payments}},true);
 assert.match(html,/64\.385,01/);assert.match(html,/Resmi API kaydı/);assert.ok(!html.includes('data-payment="2"'));assert.ok(!html.includes('data-payment="3"'));assert.ok(!html.includes('Düzenle'));
 assert.match(html,/Tarih belirlenmedi/);assert.match(html,/aylık toplama dahil değildir/);
 assert.match(paymentTable({user:{role:'admin'},data:{payments}}),/Tarih belirlenmedi/);
});
test('Güncel takvim manuel ödemeleri gösterir; eski otomatik ve eksik kanal uyarılarını taşımaz',()=>{
 const base={id:5,channel:'Pazarama',expectedDate:'',expectedCents:1090037,status:'pending',verification:'confirmed',integration:'pazarama'};
 const html=paymentCalendar({from:'2026-10-01',to:'2026-10-31',summary:{},items:[],undatedItems:[base],undatedCents:1090037,legacyItems:[{...base,id:6,channel:'n11',integration:null,legacyManual:true,excludedFromTotals:true}],missingPaymentChannels:['n11','idefix']},'2026-10-20',true);
 assert.doesNotMatch(html,/otomatik hakediş verisi henüz yok|Geçmiş elle girilmiş kayıtlar|data-calendar-edit="6"/);
 assert.match(html,/Hakediş takvimi/);assert.match(html,/<h3>Ekim 2026<\/h3>/);
});
test('Takvimde API hakedişinin düzenleme düğmesi yok',()=>{
 const html=paymentCalendar({from:'2026-10-01',to:'2026-10-31',summary:{},items:[{id:1,inCalendar:true,calendarDate:'2026-10-26',expectedDate:'2026-10-26',channel:'Pazarama',expectedCents:6438501,status:'pending',verification:'confirmed',integration:'pazarama'}]},'',true);
 assert.doesNotMatch(html,/API kaydı/);assert.doesNotMatch(html,/Hakedişler yalnızca elle girilir/);assert.ok(!html.includes('data-calendar-edit="1"'));
});
