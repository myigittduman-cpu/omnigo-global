import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentTable} from '../public/views.js';
import {paymentCalendar} from '../public/payment-calendar.js';
import {now} from '../public/ui.js';
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
