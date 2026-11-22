import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryView,deliveryPageSize} from '../public/delivery-view.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';
const order={id:'shipment:42',shipmentId:42,provider:'n11',queue:'delivered',city:'Ankara',createdAt:'2026-06-12T13:20:00+03:00',buyerName:'<script>',recipientName:'Alıcı',phone:'05',address:'Örnek adres',active:false,issues:[],products:[{title:'<img> Ürün',quantity:2}]};
const model={queue:'all',city:'all',provider:'all',page:0,selected:new Set(),data:{items:[order],cities:[],sync:{},queues:{delivered:262}}};
test('teslim edilen kayıt şehir listesine veya evrak seçimine girmez',()=>{
 const html=deliveryView(model);
 assert.doesNotMatch(html,/Teslim Edilen Siparişler|delivery-order-delivered|Sevkiyat kaydı #42|<script>|<img>|data-order=/);
 assert.match(html,/data-create disabled/);
 assert.equal(refreshedDeliverySelection(model,[order]).size,0);
});
test('yenilenen veri listeyi ve sayacı değiştirir; boş miktar uydurulmaz',()=>{
 assert.equal(deliveryPageSize,20);
 assert.doesNotMatch(deliveryView({...model,data:{...model.data,hasMore:true}}),/Her sayfada 20 kayıt|data-page=/);
 assert.match(deliveryView({...model,queue:'cancelled',data:{...model.data,hasMore:true}}),/Her sayfada 20 kayıt/);
 assert.match(deliveryView({...model,data:{...model.data,items:[],queues:{delivered:0}}}),/Bu şehirde sipariş yok/);
 assert.match(deliveryView({...model,data:{...model.data,items:[{...order,active:true,queue:undefined,products:[{title:'',quantity:null}]}]}}),/Miktar belirtilmemiş/);
 assert.doesNotMatch(deliveryView({...model,queue:'all'}),/delivery-order-delivered/);
 assert.doesNotMatch(deliveryView({...model,data:{...model.data,items:[{...order,queue:'cancelled'}]}}),/Sevkiyat kaydı #42/);
});
