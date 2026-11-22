import test from 'node:test';
import assert from 'node:assert/strict';
import {paymentTemplate,paymentTemplateNote} from '../public/payment-templates.js';
import {paymentRowsHtml,paymentDueDefault} from '../public/payment-form.js';
import {paymentDetailSections} from '../public/payment-details.js';
import {paymentRecords} from '../public/payment-records.js';
import {operationNotificationLabel} from '../public/operation-notification.js';
test('Altı panelin özel kesinti alanları ayrı ve boş başlangıçlıdır',()=>{
 for(const [channel,label] of [['n11','Pazarlama Bedeli'],['Hepsiburada','Tahsilat yönetim bedeli'],['PTT AVM','Ödeme Altyapısı Bedeli'],['idefix','Satıcı Kupon + Satıcı İndirim'],['Pazarama','Erken Ödeme Bedeli'],['Koçtaş','Diğer kredi notu']]){
 const rows=paymentTemplate(channel);assert(rows.some(r=>r.label===label));assert(rows.every(r=>r.amountCents===null&&r.rateBasisPoints===null));assert.match(paymentRowsHtml(rows),/Oran \(%\)/);
 }
 assert.match(paymentTemplateNote('Pazarama'),/KDV dahil.*KDV hariç/);assert.match(paymentTemplateNote('PTT AVM'),/yeniden düşülmez/);
});
test('İki gün sonrası ay ve yıl sınırlarında doğrudur',()=>{assert.equal(paymentDueDefault('2026-12-30'),'2027-01-01');assert.equal(paymentDueDefault('2028-02-28'),'2028-03-01');});
test('Müşteri kalemleri okur; düzenleme düğmesi yalnız yöneticiye görünür',()=>{
 const p={id:1,channel:'n11',expectedDate:'2026-10-23',expectedCents:100,status:'pending',details:[{section:'commission',label:'<script>','amountCents':0,rateBasisPoints:1250}]};
 assert.doesNotMatch(paymentRecords([p],false),/data-calendar-edit/);assert.match(paymentRecords([p],true),/data-calendar-edit/);assert.match(paymentRecords([p],false),/data-payment-detail/);
 const html=paymentDetailSections(p);assert.match(html,/%12,5/);assert.doesNotMatch(html,/<script>/);assert.match(html,/₺0,00/);
});
test('Teslimat bildirimi müşteri adıyla başlar; isim HTML olarak çalışmaz',()=>{
 assert.equal(operationNotificationLabel({events:['delivery_added'],buyerName:'Ayşe Yılmaz'}),'Ayşe Yılmaz siparişi Şehir Dışı Teslimatlar listesine eklendi');
 assert.match(operationNotificationLabel({events:['delivery_added'],buyerName:'<img src=x>'}),/^&lt;img/);
 assert.equal(operationNotificationLabel({events:['delivery_added']}),'Şehir Dışı Teslimatlar listesine eklendi');
});

test('İptal bildirimi liste ve ayrıntıda müşteri adıyla görünür',()=>{assert.equal(operationNotificationLabel({events:['cancelled'],buyerName:'Ayşe Yılmaz'}),'Ayşe Yılmaz siparişi iptal edildi');assert.match(operationNotificationLabel({events:['cancelled'],buyerName:'<script>'}),/^&lt;script&gt;/);});
