import test from 'node:test';
import assert from 'node:assert/strict';
import {orderStatusClass} from '../public/order-status-presentation.js';
import {ordersView} from '../public/orders-view.js';

test('Teslim edildi ve tamamlandı kaynak durumları ayrı rozetler kullanır',()=>{
 for(const state of ['RECEIVED','delivered','Teslim edildi'])assert.equal(orderStatusClass({state}),'delivered');
 for(const state of ['CLOSED','completed','Tamamlandı','Sipariş tamamlandı'])assert.equal(orderStatusClass({state}),'completed');
 assert.equal(orderStatusClass({statusLabel:'Teslim edildi',displayRowStatus:'completed'}),'delivered');
 assert.equal(orderStatusClass({statusLabel:'Tamamlandı',state:'RECEIVED'}),'completed');
});

test('İptal önceliği tutarlar, satır adetleri ve kayıtlar değiştirilmeden korunur',()=>{
 const order=Object.freeze({state:'RECEIVED',statusLabel:'Teslim edildi',grossCents:120000,cancelCents:0,operationalCancellation:{cancelledQuantity:1,operationalOnly:true}});
 const before=JSON.stringify(order);
 assert.equal(orderStatusClass(order),'cancelled');
 for(const state of ['CANCELED','CANCELLED','REFUSED','UNDELIVERED','İptal edildi','Teslim edilemedi'])assert.equal(orderStatusClass({state}),'cancelled');
 assert.equal(JSON.stringify(order),before);
});

test('Sevkiyat ve fatura rozetleri yalnız açık kaynak durumuna göre belirlenir',()=>{
 assert.equal(orderStatusClass({state:'SHIPPED'}),'shipped');
 assert.equal(orderStatusClass({statusLabel:'Faturası kesildi'}),'invoiced');
 assert.equal(orderStatusClass({state:'STAGING'}),'waiting');
 assert.equal(orderStatusClass({state:'MIXED'}),'');
 assert.equal(orderStatusClass({grossCents:10000,returnCents:10000}),'');
});

test('İki panelin ortak listesinde tamamlanan satır renklenir; tutar korunur ve belge sütunu görünmez',()=>{
 for(const provider of ['Hepsiburada','n11','Pazarama','idefix','Koçtaş','PTT AVM','Trendyol']){
  const order={id:42,provider,state:'RECEIVED',createdAt:'2026-10-28T08:00:00+03:00',externalId:'TEST-42',grossCents:120000,cancelCents:0,returnCents:0,products:[{title:'Örnek ürün',quantity:1}]};
  const before=JSON.stringify(order);
  const markup=ordersView({page:0,canDocuments:true,data:{total:1,items:[order]}});
  assert.match(markup,/<span class="badge order-status-badge order-delivered">Teslim edildi<\/span>/);
  assert.match(markup,/<tr data-order-id="42" class="order-row-delivered">/);
  assert.match(markup,/<tr[^>]*class="order-row-delivered"/);
  assert.match(markup,/data-order-detail="42"/);
  assert.doesNotMatch(markup,/data-order-documents|data-label="Evraklar"/);
  assert.match(markup,/1\.200,00/);
  assert.equal(JSON.stringify(order),before);
 }
});
