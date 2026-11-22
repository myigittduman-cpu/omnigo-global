import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryView,ready,deliveryCities} from '../public/delivery-view.js';
import {deliveryFilename} from '../public/delivery-filename.js';
import {deliveryVerificationMessage} from '../public/delivery-document-guard.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';

test('city selection includes previously downloaded active orders, excludes incomplete records',()=>{
 const order={id:1,city:'Eskişehir',provider:'Koçtaş',active:true,documentId:15,issues:[],products:[],status:'Sevkiyat bekliyor'};
 assert.equal(ready(order),true);
 assert.equal(ready({...order,active:false}),false);
 assert.equal(ready({...order,issues:['Telefon eksik']}),false);
 assert.equal(ready({...order,city:'Diğer / Kontrol'}),false);
 const html=deliveryView({city:order.city,provider:'all',date:'2026-10-10',page:0,selected:new Set([1]),data:{items:[order],cities:[],sync:{}}});
 assert.doesNotMatch(html,/Evrak arşivi|data-document|data-mode/);
 assert.match(html,/Toplam: 1 sipariş · 0 parça ürün/);assert.match(html,/data-order="1"\s+checked/);
});
test('only supported cities produce the requested Turkish PDF filename',()=>{
 const context={company:{name:'Vizyon Beyaz Eşya'},provider:'all',downloadedAt:'2026-10-19T10:00:00Z'};
 for(const city of deliveryCities)assert.equal(deliveryFilename(city,'pdf',context),`Vizyona – 19.10.2026 Teslimat Evrakları.pdf`);
 for(const city of ['../Ankara','Diğer / Kontrol',''])assert.throws(()=>deliveryFilename(city));
});
test('kaldırılan sevkiyat teyit işaretleri aktif ve eksiksiz siparişin evrakını engellemez',()=>{
 const normal={id:1,city:'Ankara',provider:'Hepsiburada',active:true,issues:[],products:[{title:'Test ürün',quantity:1}],status:'Sevk bekliyor'};
 for(const extra of [{fulfillmentProducts:{review:[normal.products[0]]}},{fulfillmentNeedsReview:true,fulfillmentReviewProducts:[normal.products[0]]}]){
  const blocked={...normal,id:2,...extra};
  assert.equal(ready(blocked),true);
  const selected=refreshedDeliverySelection({queue:'all',manualSelection:false,selected:new Set()},[normal,blocked]);
  assert.deepEqual([...selected],[1,2]);
  const html=deliveryView({queue:'all',city:'Ankara',page:0,selected,date:'2026-10-20',data:{items:[normal,blocked],cities:[],sync:{}}});
  assert.equal((html.match(/<article class="delivery-order /g)||[]).length,2);
  assert.equal(deliveryVerificationMessage,'');assert.doesNotMatch(html,/data-order="2" disabled/);
  assert.match(html,/Toplam: 2 sipariş · 2 parça ürün/);assert.match(html,/data-order="2"[^>]*checked/);
 }
 const confirmed={...normal,statusMismatch:true,fulfillmentNeedsReview:false,fulfillmentReviewProducts:[]};
 assert.equal(ready(confirmed),true);
 assert.equal(ready(normal),true);
});
