import test from 'node:test';
import assert from 'node:assert/strict';
import {defaultSalesRange} from '../public/sales-analytics.js';
import {topProductsView,productImage} from '../public/top-products.js';
import {channelSalesMetrics} from '../public/sales-panels.js';
import {money} from '../public/ui.js';

test('Tarih başlangıcı ayın ilk günü, sonu bugün veya seçili ayın sonudur',()=>{
 assert.deepEqual(defaultSalesRange('2026-10','2026-10-09'),{from:'2026-10-01',to:'2026-10-09'});
 assert.deepEqual(defaultSalesRange('2024-02','2026-10-09'),{from:'2024-02-01',to:'2024-02-29'});
 assert.deepEqual(defaultSalesRange('2026-09','2026-10-09'),{from:'2026-09-01',to:'2026-09-30'});
});
test('Günlük sıfır ayrı, aylık tutar ayrı gösterilir',()=>{
 const html=channelSalesMetrics({name:'Koçtaş'},[{channel:'Koçtaş',month:'2026-10',grossCents:10000,cancelCents:1000,returnCents:0,orders:3}], '2026-10',{date:'2026-10-09',channels:[{channel:'Koçtaş',available:true,netCents:0,orders:0}]});
 assert.ok(html.includes(money(9000))&&html.includes(money(0)));assert.match(html,/3 sipariş/);assert.match(html,/0 sipariş/);assert.match(html,/Bu ay toplam satış/);
});
test('Ürün slaytı kaynak metinlerini kaçırır, gerçek sayı kadar ürün gösterir',()=>{
 const html=topProductsView([{sku:'<script>',title:'<img onerror="x">',netQuantity:8,netCents:12000,imageUrl:null}]);
 assert.doesNotMatch(html,/<script>|<img onerror/);assert.match(html,/&lt;script&gt;/);assert.match(html,/01 \/ 01/);assert.match(html,/8 adet/);assert.match(html,/Görsel bekleniyor/);assert.match(html,/Duraklat/);
 assert.doesNotMatch(productImage({imageUrl:'javascript:alert(1)',title:'test'}),/<img /);
 assert.doesNotMatch(productImage({imageUrl:'https://user:pass@example.test/a.jpg',title:'test'}),/<img /);
 assert.match(productImage({imageUrl:'https://images.example.test/a.jpg',title:'test'}),/referrerpolicy="no-referrer"/);
 assert.match(topProductsView([]),/doğrulanmış ürün satışı bulunmuyor/);
});
