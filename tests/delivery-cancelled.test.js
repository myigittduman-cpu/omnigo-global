import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryView} from '../public/delivery-view.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';
const order={id:3,orderNumber:'<script>',createdAt:'2026-10-18T08:00:00+03:00',provider:'Hepsiburada',city:'Ankara',active:false,issues:[],products:[],queue:'cancelled',queueProducts:[{title:'<img> iptal ürün',quantity:2}],cancellation:{quantity:2,cancelledQuantity:2,partial:false}};
const model={queue:'cancelled',city:'all',provider:'all',selected:new Set(),page:0,data:{items:[order],cities:[],sync:{}}};
test('iptal listesi ayrı sayfada; kırmızı kartta ürün/miktar var, PDF seçimi yok',()=>{
 const html=deliveryView(model);
 assert.doesNotMatch(deliveryView({...model,screen:'selection'}),/data-delivery-queue="(?:shipped|delayed)"/);
 assert.match(html,/delivery-order-cancelled/);assert.match(html,/İptal edilen ürünler/);assert.match(html,/2 adet/);assert.match(html,/İptal edildi/);
 assert.doesNotMatch(html,/<script>|<img>|data-order=|data-select-all|data-create|data-excel|data-delivery-share|data-date/);
 assert.equal(refreshedDeliverySelection(model,[order]).size,0);
});
test('kısmi iptal anlaşılır; boş menü ve aktif liste birbirinden ayrı',()=>{
 assert.match(deliveryView({...model,data:{...model.data,items:[{...order,active:true,cancellation:{...order.cancellation,partial:true}}]}}),/Kısmi iptal/);
 assert.match(deliveryView({...model,data:{...model.data,items:[]}}),/İptal edilen sipariş yok/);
 assert.doesNotMatch(deliveryView({...model,queue:'all',city:'Ankara'}),/delivery-order-cancelled/);
});
test('menü sayaçları sıfır dahil gerçek toplamları gösterir',()=>{
 const html=deliveryView({...model,screen:'selection',data:{...model.data,queues:{delayed:0,shipped:511,cancelled:38},cities:[{name:'Diğer / Kontrol',count:4}]}});
 assert.doesNotMatch(html,/Geciken Siparişler/);
 assert.doesNotMatch(html,/Kargodaki Siparişler/);
 assert.match(html,/İptal Edilen Siparişler<\/span><strong>38<\/strong>/);
 assert.match(html,/Diğer \/ Kontrol<\/span>[\s\S]*?<strong>4 sipariş/);
});
