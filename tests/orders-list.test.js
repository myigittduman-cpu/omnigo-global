import test from 'node:test';
import assert from 'node:assert/strict';
import {ordersView} from '../public/orders-view.js';
test('Sipariş listesinde güvenli ürün metni, bilinmeyen adet ve para ayrımı korunur',()=>{
 const html=ordersView({page:0,from:'2026-10-01',to:'2026-10-11',provider:'all',data:{total:1,items:[{id:1,externalId:'<script>123</script>',provider:'n11',createdAt:'2026-10-11T09:00:00Z',state:'Aktarıldı',grossCents:300000,cancelCents:100000,returnCents:0,products:[{title:'<img> ürün',quantity:null}]}]}});
 assert.doesNotMatch(html,/<script>|<img> ürün/);assert.match(html,/data-label="Adet">—/);assert.match(html,/İptal sonrası tutar/);assert.match(html,/₺2\.000,00/);assert.match(html,/İptal edildi/);assert.match(html,/class="badge order-status-badge order-cancelled"/);
});
