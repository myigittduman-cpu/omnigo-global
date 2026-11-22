import test from 'node:test';
import assert from 'node:assert/strict';
import {orderDetailView} from '../public/order-detail.js';
import {orderedNavigation} from '../public/navigation-order.js';
test('Sipariş ayrıntısı kişisel metinleri HTML olarak çalıştırmaz, eksik fatura alanını uydurmaz',()=>{
 const html=orderDetailView({provider:'n11',createdAt:'2026-10-10T00:00:00Z',grossCents:100,products:[],packages:[{complete:true,customerName:'<img src=x onerror=alert(1)>',deliveryAddress:'<script>bad</script>',phone:'05000000000'}]});
 assert.doesNotMatch(html,/<img|<script/);assert.match(html,/Aktarılmadı/);assert.match(html,/05000000000/);
});
test('Menü sırası tüm hesaplarda ortaktır; eski kişisel sıralama uygulanmaz',()=>{
 const names={a:'Bir',b:'İki',c:'Üç'},storage={getItem:key=>key==='omnigo:admin-menu:1:v1'?'["b","b","unknown","a"]':null};
 assert.deepEqual(Object.keys(orderedNavigation(names,{id:1,role:'admin'},storage)),['a','b','c']);
 assert.deepEqual(orderedNavigation(names,{id:2,role:'admin'},storage),names);
 assert.deepEqual(orderedNavigation(names,{id:1,role:'client'},storage),names);
});
