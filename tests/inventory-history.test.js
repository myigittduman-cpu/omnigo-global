import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryHistoryView} from '../public/inventory-history-view.js';

test('Kullanıcı, Türkiye tarihi, satış adedi ve önceki/sonraki stok açıkça gösterilir',()=>{
 const html=inventoryHistoryView({items:[{kind:'store_sale',created:'2026-10-29T21:30:00Z',actor_name:'Kullanıcı <A>',title:'Ürün <x>',sku:'SKU1',quantity_before:12,delta:-3,quantity_after:9}],nextCursor:'cursor'});
 assert.match(html,/30\.10\.2026/);assert.match(html,/00:30/);assert.match(html,/Mağaza satışı/);
 assert.match(html,/Kullanıcı &lt;A&gt;/);assert.doesNotMatch(html,/<A>|<x>/);
 assert.match(html,/<td>12<\/td><td><strong>-3<\/strong><\/td><td>9<\/td>/);
 assert.match(html,/data-history-prev disabled/);assert.doesNotMatch(html,/data-history-next disabled/);
});

test('Ürün hareketi ürün sütununu tekrarlamaz ve ilk sayım bilinmeyen önceki stok gösterir',()=>{
 const html=inventoryHistoryView({items:[{kind:'initialize',created:'2026-10-30T10:00:00Z',actor_name:'Ayşe',quantity_before:null,delta:10,quantity_after:10}],nextCursor:null},{product:true,page:1});
 assert.doesNotMatch(html,/Ürün \/ stok kodu/);assert.match(html,/<td>—<\/td><td><strong>\+10/);
 assert.match(html,/data-history-next disabled/);assert.doesNotMatch(html,/data-history-prev disabled/);
 assert.match(inventoryHistoryView({items:[]}),/Bu tarih aralığında stok hareketi yok/);
});
test('Pazaryeri talebi bekleme/başarı/hata durumlarını ve aynı adedi gösterir; hata metni güvenle yazılır',()=>{
 const row={kind:'marketplace_stock_update',created:'2026-11-06T00:00:00Z',actor_name:'Örnek yönetici',quantity_before:7,delta:null,quantity_after:null};
 for(const [state,label,tone]of [['queued','Güncelleme bekleniyor','pending'],['accepted','Güncelleme bekleniyor','pending'],['succeeded','Güncelleme başarılı','success'],['failed','Güncelleme başarısız','error']]){
  const html=inventoryHistoryView({marketplaceManagement:true,items:[{...row,marketplaceRequest:{id:'request-123456789',state,provider:'Hepsiburada',quantity:9,error:state==='failed'?'<Hata>':null,retryable:state==='failed'}}]});
  assert(html.includes(label));assert(html.includes('is-'+tone));assert(html.includes('İstenen stok: 9 adet'));
  if(state==='failed'){assert(html.includes('data-stock-retry-quantity="9"'));assert(html.includes('&lt;Hata&gt;'));}else assert(!html.includes('data-stock-retry='));
 }
});
