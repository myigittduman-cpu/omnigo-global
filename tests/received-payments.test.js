import test from 'node:test';
import assert from 'node:assert/strict';
import {applyPaymentApproval,reportedPaymentItems,receivedPaymentItems,expectedPaymentItems,paymentListItems,receivedPaymentPage,paymentReceiptDifference} from '../public/received-payments-data.js';
import {paymentCalendarEvents} from '../public/payment-calendar-events.js';
import {receivedPaymentsView} from '../public/received-payments-view.js';
import {paymentCalendar} from '../public/payment-calendar.js';
import {paymentDifferenceNote} from '../public/payment-difference-note.js';
const paid={id:1,version:1,status:'paid',channel:'n11',expectedDate:'2026-10-20',paidDate:'2026-11-02',calendarDate:'2026-11-02',inCalendar:true,expectedCents:3061122,actualCents:3061022,confirmation:'Banka ekstresi',paymentApproval:{at:'2026-11-07T00:00:00Z',userId:1}};
const report={from:'2026-11-01',to:'2026-11-30',summary:{receivedCents:paid.actualCents},items:[paid]};

test('Hesaba geçen listesi, banka tarihi seçili döneme düşen gerçek ödemeleri gösterir',()=>{
 const items=[paid,{...paid,id:2,status:'pending'},{...paid,id:3,status:'sent'},{...paid,id:4,paidDate:'2026-12-01',expectedDate:'2026-11-02'},{...paid,id:5,integration:'old'},{...paid,id:6,excludedFromTotals:true},{...paid,id:7,paymentStateUnknown:true},{...paid,id:8,paidDate:'2026-11-01'},{...paid,id:9,actualCents:0}];
 const before=structuredClone(items),rows=receivedPaymentItems({...report,items});
 assert.deepEqual(rows.map(item=>item.id),[9,1,8]);
 assert.deepEqual(items,before);
 assert.equal(rows.reduce((sum,item)=>sum+item.actualCents,0),paid.actualCents*2);
});

test('27 kayıt 10,10,7 olarak sayfalanır; kayıt kimliği ve tutarı aynı satırda kalır',()=>{
 const data={...report,items:Array.from({length:27},(_,i)=>({...paid,id:i+1,actualCents:i+20}))};
 const pages=[1,2,3].map(page=>receivedPaymentPage(data,page));
 assert.deepEqual(pages.map(page=>page.items.length),[10,10,7]);
 assert.equal(new Set(pages.flatMap(page=>page.items.map(item=>item.id))).size,27);
 assert.ok(pages.flatMap(page=>page.items).every(item=>item.actualCents===item.id+19));
 assert.equal(receivedPaymentPage(data,999).page,3);
 assert.equal(receivedPaymentPage(data,NaN).page,1);
 assert.equal(receivedPaymentPage({...data,items:[]},3).page,1);
 const html=receivedPaymentsView(data,2,true);
 assert.equal((html.match(/data-received-payment=/g)||[]).length,10);
 assert.match(html,/data-received-page="2"[^>]*aria-current="page"/);
 assert.match(html,/data-received-page="3"/);
});

test('Takvim bekleyen ödemeyi gösterir ve alınan toplamın detaylarını korur',()=>{
 const data={...report,items:[paid,{...paid,id:2,status:'pending',paymentApproval:null,channel:'Koçtaş',expectedCents:1200}]};
 const html=paymentCalendar(data,'',true);
 assert.match(html,/data-paid-payments/);assert.match(html,/>Detaylar<\/button>/);
 assert.match(html,/30\.610,22/);
 assert.match(html,/2 Kasım 2026 · 1 ödeme kaydı/);
 assert.doesNotMatch(html,/Platformlara göre ödemeler|>n11</);
 assert.match(html,/data-payment-day="2026-11-02"/);
});

test('Müşteri listesinde düzenleme yoktur; boş durum ve güvenli metin korunur',()=>{
 assert.doesNotMatch(receivedPaymentsView(report,1,false),/data-received-receipt/);
 assert.doesNotMatch(receivedPaymentsView(report,1,true),/data-received-receipt="1"/);
 assert.match(receivedPaymentsView({...report,items:[{...paid,paymentApproval:null}]},1,true,'pending'),/data-received-receipt="1"/);
 assert.match(receivedPaymentsView({...report,items:[]}),/Seçili dönemde onaylanmış ödeme bulunmuyor/);
 const html=receivedPaymentsView({...report,items:[{...paid,channel:'<img src=x>',confirmation:'<script>hata</script>'}]});
 assert.doesNotMatch(html,/<img|<script|hata/);assert.match(html,/&lt;img src=x&gt;/);
});

test('Kuruş farkı eksik ve fazla olarak gösterilir; eşit veya bekleyen ödemede not üretilmez',()=>{
 assert.equal(paymentReceiptDifference(paid),-100);
 assert.match(paymentDifferenceNote(paid),/Planlanandan[^<]+1,00[^<]+eksik/);
 assert.match(paymentDifferenceNote({...paid,actualCents:3061222}),/1,00[^<]+fazla/);
 assert.equal(paymentDifferenceNote({...paid,actualCents:paid.expectedCents}),'');
 assert.equal(paymentDifferenceNote({...paid,status:'sent'}),'');
 assert.match(receivedPaymentsView(report),/payment-difference-note/);
});

test('Ödeme sonrası kayıt bekleyenlerden çıkar; banka tarihi değişirse doğru aya geçer',()=>{
 const pending={...paid,status:'pending',actualCents:null};
 assert.equal(receivedPaymentItems({...report,items:[pending]}).length,0);
 assert.equal(receivedPaymentItems(report).length,1);
 assert.equal(receivedPaymentItems({...report,from:'2026-10-01',to:'2026-10-31'}).length,0);
 assert.equal(receivedPaymentItems({...report,items:[{...paid,paidDate:'2026-12-01'}]}).length,0);
});

test('Aktarım bildirimi onaylanana kadar bekleyende kalır; iki sekme kayıtları ve farkı karıştırmaz',()=>{
 const reported={...paid,id:3,paymentApproval:null},pending={...paid,id:4,status:'pending',paymentApproval:null,actualCents:null};
 const data={...report,items:[paid,reported,pending]};
 assert.deepEqual(receivedPaymentItems(data).map(item=>item.id),[1]);
 assert.deepEqual(expectedPaymentItems(data).map(item=>item.id),[3,4]);
 const expected=receivedPaymentsView(data,1,true,'pending'),received=receivedPaymentsView(data,1,true,'received');
 assert.match(expected,/Ödeme onayı bekliyor/);assert.match(expected,/Planlanandan[^<]+1,00[^<]+eksik/);
 assert.match(expected,/data-received-approve="3"/);assert.doesNotMatch(expected,/data-received-approve="4"/);
 assert.match(received,/data-received-payment="1"/);assert.doesNotMatch(received,/data-received-payment="3"|data-received-payment="4"/);
 const after={...data,items:[paid,{...reported,paymentApproval:{at:'2026-11-07T01:00:00Z'}},pending]};
 assert.deepEqual(receivedPaymentItems(after).map(item=>item.id),[3,1]);
 assert.deepEqual(expectedPaymentItems(after).map(item=>item.id),[4]);
 assert.equal(new Set([...receivedPaymentItems(after),...expectedPaymentItems(after)].map(item=>item.id)).size,3);
 assert.match(receivedPaymentsView(after,1,false,'received'),/Planlanandan[^<]+1,00[^<]+eksik/);
 assert.doesNotMatch(receivedPaymentsView(data,1,false,'pending'),/data-received-(?:receipt|approve)=/);
});

test('Ödendi Bildirimi ve takvim onaylanan kaydı aynı anda doğru toplam ve duruma taşır',()=>{
 const waiting={...paid,id:2,expectedDate:'2026-11-02',paymentApproval:null,actualCents:50000};
 const onlySent={...waiting,id:3,status:'sent',actualCents:null,expectedCents:7000};
 const estimate={...waiting,id:4,status:'pending',actualCents:null};
 const outside={...waiting,id:5,paidDate:'2026-12-01'};
 const data={...report,items:[paid,waiting,onlySent,estimate,outside],summary:{sentCents:57000,receivedCents:paid.actualCents}};
 const notice=()=>receivedPaymentsView(data,1,true,'reported',null,{notice:true});
 assert.deepEqual(reportedPaymentItems(data).map(row=>row.id),[3,2]);
 assert.match(notice(),/Ödeme Beklenen[\s\S]*Hesaba Geçen/);
 assert.match(notice(),/Banka teyidi bekleniyor[\s\S]*Ödeme onayı bekliyor/);
 assert.match(notice(),/data-received-approve="2">Ödeme onayı/);
 assert.doesNotMatch(notice(),/Takvim Kayıtları|data-received-approve="3"|data-received-payment="[145]"/);
 const approved={...waiting,version:2,paymentApproval:paid.paymentApproval};
 assert.equal(applyPaymentApproval(data,approved),true);
 assert.equal(applyPaymentApproval(data,approved),true);
 assert.equal(data.summary.sentCents,7000);
 assert.equal(data.summary.receivedCents,paid.actualCents+50000);
 assert.deepEqual(reportedPaymentItems(data).map(row=>row.id),[3]);
 assert.deepEqual(receivedPaymentItems(data).map(row=>row.id),[2,1]);
 assert.equal(paymentCalendarEvents(data).some(row=>row.id===2),false);
 assert.match(receivedPaymentsView(data,1,true,'received',null,{notice:true}),/data-received-payment="2"[\s\S]*>Ödendi</);
 assert.doesNotMatch(receivedPaymentsView(data,1,false,'reported',null,{notice:true}),/data-received-approve|data-received-receipt/);
});

test('Beklenen ödemeler de 10 kayıtla sayfalanır; onaylanan ödeme bekleyen sayfasında görünmez',()=>{
 const data={...report,items:Array.from({length:26},(_,i)=>({...paid,id:i+1,paymentApproval:i===25?paid.paymentApproval:null}))};
 const pages=[1,2,3].map(page=>receivedPaymentPage(data,page,'pending'));
 assert.deepEqual(pages.map(page=>page.items.length),[10,10,5]);
 assert.equal(new Set(pages.flatMap(page=>page.items.map(item=>item.id))).size,25);
 const html=receivedPaymentsView(data,2,true,'pending');
 assert.equal((html.match(/data-received-payment=/g)||[]).length,10);
 assert.match(html,/data-payment-list-tab="pending" aria-pressed="true"/);
 assert.match(html,/data-received-page="2"[^>]*aria-current="page"/);
});

test('Takvim kayıtları onaylanan pazaryeri tahminleri ve manuel kayıtları aynı 10 satırlık tabloda gösterir',()=>{
 const manual={id:51,version:1,status:'pending',channel:'Koçtaş',expectedDate:'2026-11-20',calendarDate:'2026-11-20',
  inCalendar:true,expectedCents:90000};
 const events=Array.from({length:11},(_,index)=>({
  id:'net-'+index,channel:'Hepsiburada',date:'2026-11-'+(16+index),amountCents:3000+index,
  source:'marketplace',verification:'estimated',
 }));
 const data={...report,items:[manual,{...manual,id:52,channel:'Hepsiburada',expectedDate:'2026-11-16',calendarDate:'2026-11-16'}],
  upcoming:{today:'2026-11-15',periodItems:[...events,{id:51,channel:'Koçtaş',date:'2026-11-20',amountCents:90000,
   source:'manual',verification:'estimated'}]},marketplaces:['Hepsiburada','Koçtaş']};
 const rows=paymentListItems(data,'calendar');
 assert.equal(rows.length,12);
 assert.equal(rows.some(item=>item.id===52),false);
 assert.equal(rows.find(item=>item.id===51).forecastReadOnly,false);
 assert.equal(rows.find(item=>item.id==='net-0').forecastReadOnly,true);
 assert.deepEqual([1,2].map(page=>receivedPaymentPage(data,page,'calendar').items.length),[10,2]);
 const markup=receivedPaymentsView(data,1,true,'calendar',{mode:false});
 assert.match(markup,/received-payments-tools[\s\S]*data-payment-select="start"[\s\S]*data-payment-marketplace/);
 assert.match(markup,/data-received-payment="net-0"[\s\S]*Pazaryeri API’si/);
 assert.match(markup,/data-received-edit="51"/);
 assert.doesNotMatch(markup,/data-received-detail="NaN"|data-received-edit="net-/);
});
