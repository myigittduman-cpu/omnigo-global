import test from 'node:test';
import assert from 'node:assert/strict';
import {orderPricing,orderPricingView,orderAmountView} from '../public/order-pricing.js';
import {ordersView} from '../public/orders-view.js';
import {orderDetailView} from '../public/order-detail.js';
const product=()=>({title:'Örnek ürün',quantity:1,grossCents:12345,pricing:{originalCents:15000,platformDiscountCents:2000,sellerDiscountCents:655,discountedCents:12345,reportCents:12345,reportBasis:'discounted'}});
const order=()=>({id:1,provider:'idefix',externalId:'TEST',createdAt:'2026-10-17T12:00:00+03:00',grossCents:24690,cancelCents:12345,returnCents:0,refundsVerified:true,products:[product(),product()]});
test('Listede doğru tutar ve ayrıntı düğmesi görünür; tam indirim dökümü ayrıntıda korunur',()=>{
 const row=order(),html=ordersView({page:0,from:'2026-10-01',to:'2026-10-17',provider:'idefix',data:{total:1,items:[row]}});
 for(const text of ['123,45','İptal sonrası tutar'])assert.ok(html.includes(text),text);
 assert.doesNotMatch(html,/İptal:|246,90|Tutar dökümü/);
 assert.doesNotMatch(html,/İndirim öncesi|Pazaryeri indirimi|Satış hesabında/);
 const detail=orderDetailView(row);
 for(const text of ['İndirim öncesi','300,00','Pazaryeri indirimi','40,00','Satıcı indirimi','13,10','İndirim sonrası','246,90'])assert.ok(detail.includes(text),text);
 assert.doesNotMatch(orderPricingView(row),/<button|<details/);
 assert.match(orderDetailView(row),/Tutar dökümü/);
 assert.equal(row.grossCents,24690);assert.equal(row.cancelCents,12345);
});
test('Eksik veya çelişkili kalemler sıfır indirim olarak gösterilmez',()=>{
 for(const change of [o=>delete o.products[0].pricing,o=>o.products[0].pricing.originalCents++,o=>o.products[0].pricing.sellerDiscountCents=-1,o=>o.products[0].pricing.reportBasis='<script>',o=>o.grossCents++]){
  const row=order();change(row);assert.equal(orderPricing(row),null);assert.doesNotMatch(orderAmountView(row),/İndirim yok|İndirimli sipariş|<script>/);assert.match(orderPricingView(row,{detailed:true}),/henüz aktarılmadı/);
 }
});
test('Kaynak gerçekten sıfır indirim gönderirse indirimsiz kayıt açıkça belirtilir',()=>{
 const row=order();for(const p of row.products){p.pricing.originalCents=p.pricing.discountedCents;p.pricing.sellerDiscountCents=p.pricing.platformDiscountCents=0;}
 assert.match(orderAmountView(row),/İndirim yok/);assert.doesNotMatch(orderAmountView(row),/İndirimli sipariş/);
});
test('Pazarama ana tutarı fatura değeridir; müşteri ödemesi yalnız ayrıntıda ayrı gösterilir',()=>{
 const row={...order(),provider:'Pazarama',grossCents:2029900,cancelCents:0,products:[{title:'Örnek ürün',quantity:1,grossCents:2029900,pricing:{originalCents:2049900,platformDiscountCents:81996,sellerDiscountCents:20000,discountedCents:1947904,reportCents:2029900,reportBasis:'seller_invoice'}}]};
 assert.match(orderAmountView(row),/20\.299,00/);assert.doesNotMatch(orderAmountView(row),/19\.479,04/);
 const detail=orderPricingView(row,{detailed:true});
 assert.match(detail,/Müşterinin ödediği<\/dt><dd>.*19\.479,04/);
 assert.match(detail,/Fatura tutarı<\/dt><dd>.*20\.299,00/);
 assert.doesNotMatch(detail,/Panelde alıcının ödediği tutar gösterilir/);
});
