import test from 'node:test';
import assert from 'node:assert/strict';
import {orderPricing,orderPricingView} from '../public/order-pricing.js';
import {ordersView} from '../public/orders-view.js';
import {orderDetailView} from '../public/order-detail.js';
import {shipmentRow} from '../public/shipments-view.js';
import {deliveryView} from '../public/delivery-view.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';
import {createShipmentWorkbook} from '../public/shipment-export.js';
const order=()=>({id:1,provider:'PTT AVM',createdAt:'2026-10-19T00:12:24+03:00',externalId:'PTT-TEST',grossCents:2704411,cancelCents:0,returnCents:0,refundsVerified:false,returnAmountVerified:true,customerNames:[],recipientNames:['Teslimat Alıcısı'],products:[{title:'Ürün',quantity:1,grossCents:2704411,pricing:{originalCents:3049900,platformDiscountCents:345489,sellerDiscountCents:0,couponCents:10000,discountedCents:2704411,reportCents:2704411,reportBasis:'discounted',invoiceBasis:'ptt_coupon_included'}}]});
test('PTT kupon dökümü kaynak fatura tutarını korur',()=>{
 const o=order(),before=JSON.stringify(o),view=orderPricingView(o,{detailed:true});
 for(const text of ['30.499,00','3.354,89','27.144,11','100,00','27.044,11','Kupon','Fatura tutarı'])assert.ok(view.includes(text),text);
 assert.doesNotMatch(view,/26\.944,11/);assert.equal(JSON.stringify(o),before);
 o.products[0].pricing.couponCents=400000;assert.equal(orderPricing(o),null);
});
test('Bilinen PTT iade tutarı görünür, eksik kaynak gizlenmez',()=>{
 const o=order();assert.doesNotMatch(orderDetailView(o),/Henüz aktarılmadı/);
 o.returnAmountVerified=false;assert.match(orderDetailView(o),/Henüz aktarılmadı/);
 o.returnAmountVerified=true;o.returnCents=2704411;assert.match(orderDetailView(o),/İade <strong>.*27\.044,11/);
});
test('Güncel durum ve iptal önceliği korunur; başka pazaryeri değişmez',()=>{
 const render=o=>ordersView({page:0,data:{total:1,items:[o]}});
 assert.match(render({...order(),provider:'n11',state:'Aktarıldı',statusLabel:'Kargolandı'}),/class="badge order-status-badge order-shipped">Kargolandı/);
 assert.match(render({...order(),provider:'Pazarama',statusLabel:'Teslim edildi',cancelCents:2704411}),/İptal edildi/);
 assert.match(render({...order(),provider:'Koçtaş',state:'RECEIVED'}),/class="badge order-status-badge order-delivered">Teslim edildi/);
});
test('PTT alıcısı sipariş sahibi gibi sunulmaz; kullanıcı düzeltmesi korunur',()=>{
 const o=order(),list=ordersView({page:0,data:{total:1,items:[o]}});
 assert.match(list,/Sipariş sahibi bildirilmedi.*Alıcı: Teslimat Alıcısı/);
 const detail=orderDetailView({...o,packages:[{complete:true,customerName:'',customerNameUnavailable:true,recipientName:'Teslimat Alıcısı',invoiceName:'Fatura Kişisi'}]});
 assert.match(detail,/PTT AVM API’si sipariş sahibini bildirmiyor/);assert.match(detail,/Teslimat alıcısı/);assert.match(detail,/Fatura Kişisi/);
 const shipment={id:1,provider:'PTT AVM',customerName:'Teslimat Alıcısı',recipientName:'Teslimat Alıcısı',customerNameSource:'recipient'};
 const row=shipmentRow(shipment);
 assert.match(row,/Sipariş sahibi bildirilmedi/);
 assert.match(row,/Müşteri adı: —/);
 assert.match(row,/Alıcı adı: Teslimat Alıcısı/);
 assert.doesNotMatch(row,/Müşteri adı: Teslimat Alıcısı/);
 assert.match(shipmentRow({...shipment,customerName:'Sipariş Sahibi',customerNameSource:'manual'}),/Müşteri adı: Sipariş Sahibi/);
 const delivery={...o,active:true,issues:[],city:'Ankara',buyerName:'Teslimat Alıcısı',orderNumber:'PTT-TEST',details:{recipientName:'Teslimat Alıcısı'}};
 const model={selected:new Set(),city:'all',page:0,data:{items:[delivery],cities:[],sync:{}}};
 assert.match(deliveryView(model),/<small>Teslimat alıcısı<\/small><strong>Teslimat Alıcısı/);
 delivery.buyerName='Sipariş Sahibi';delivery.manualCorrections={buyerName:'Sipariş Sahibi'};
 assert.match(deliveryView(model),/<small>Siparişi veren kişi<\/small><strong>Sipariş Sahibi/);
});
test('PTT alıcı ayrımı Excel çıktısında da korunur; miktar ve fatura tutarı aynı kalır',async()=>{
 const data={provider:'PTT AVM',city:'all',month:'2026-10',total:1,items:[{id:1,customerName:'Teslimat Alıcısı',recipientName:'Teslimat Alıcısı',customerNameSource:'recipient',quantity:1,invoiceCents:2704411}]};
 const before=JSON.stringify(data),book=new Workbook();await book.xlsx.load(await (await createShipmentWorkbook(data)).arrayBuffer());
 const sheet=book.getWorksheet('Sevkiyatlar');
 assert.equal(sheet.getCell('P8').value,'Sipariş sahibi bildirilmedi; alıcı: Teslimat Alıcısı');
 assert.equal(sheet.getCell('B8').value,'Teslimat Alıcısı');assert.equal(sheet.getCell('G8').value,1);assert.equal(sheet.getCell('H8').value,27044.11);assert.equal(JSON.stringify(data),before);
});
