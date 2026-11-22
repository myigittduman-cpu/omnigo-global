import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryView,ready} from '../public/delivery-view.js';
import {shipmentRow} from '../public/shipments-view.js';
import {cancellationLabel} from '../public/order-cancellation.js';
test('Pazaryeri iptali iki portalın ortak sevkiyat satırında manuel rengi bastırır',()=>{
 const row={id:1,customerName:'<script>',rowStatus:'invoiced',marketplaceCancellation:{quantity:2,cancelledQuantity:1,partial:true}};
 const html=shipmentRow(row);assert.match(html,/shipment-source-cancelled/);assert.match(html,/İptal edildi · 1 adet/);assert.doesNotMatch(html,/<script>/);
});
test('İptal teslimat listesinden çıkar; kalan geçerli ürün seçilebilir',()=>{
 const order={id:1,orderNumber:'1',provider:'n11',active:false,issues:[],city:'Ankara',status:'İptal edildi',products:[],cancelledProducts:[{title:'Ürün',quantity:2}],cancellation:{quantity:2,cancelledQuantity:2,partial:false}};
 assert.equal(ready(order),false);
 const html=deliveryView({city:'Ankara',selected:new Set(),data:{items:[order],cities:[]}});assert.doesNotMatch(html,/data-order="1"/);assert.doesNotMatch(html,/İptal edildi/);assert.match(html,/Bu şehirde sipariş yok/);
 assert.equal(ready({...order,active:true,products:[{title:'Ürün',quantity:1}],cancellation:{quantity:2,cancelledQuantity:1,partial:true}}),true);
 assert.equal(cancellationLabel({returnCents:500,cancelCents:0}),'');
});
