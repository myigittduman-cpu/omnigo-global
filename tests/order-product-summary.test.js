import test from 'node:test';
import assert from 'node:assert/strict';
import {summarizeOrderProducts} from '../public/order-product-summary.js';
import {ordersView} from '../public/orders-view.js';

test('Aynı ürün altı kaynak satırı yerine toplam adetle görünür; kaynak değişmez',()=>{
 const products=Array.from({length:6},(_,i)=>Object.freeze({title:'Profilo 50PA525EQG',sku:'TV-1',productCode:'TV-1',invoiceProductIdentity:'verified-tv',lineId:String(i),quantity:1,grossCents:1885908}));
 const before=JSON.stringify(products);
 assert.deepEqual(summarizeOrderProducts(products),[{title:'Profilo 50PA525EQG',quantity:6}]);
 const row={id:1,externalId:'IDE-example',provider:'idefix',cities:['Eskişehir'],deliveryAddresses:['Gizli tam adres'],products,grossCents:11315448,cancelCents:0,returnCents:0};
 const html=ordersView({page:0,from:'2026-10-01',to:'2026-10-28',data:{total:1,items:[row]}});
 assert.equal(html.split('Profilo 50PA525EQG').length-1,1);
 assert.match(html,/<th>SKU\/Adet<\/th>/);
 assert.match(html,/shipment-product-quantity">6 adet/);
 assert.match(html,/data-label="Adet">6/);
 assert.match(html,/data-label="Şehir"[^>]*>Eskişehir/);
 assert.doesNotMatch(html,/Gizli tam adres|<th>Teslimat adresi/);
 assert.match(html,/113\.154,48/);assert.equal(JSON.stringify(products),before);
});

test('SKU aynı olsa da doğrulanmış farklı kimlikler ve belirsiz satırlar birleşmez',()=>{
 const products=[
  {title:'Model A',productCode:'SKU-1',invoiceProductIdentity:'model-a',quantity:1},
  {title:'Model B',productCode:'SKU-1',invoiceProductIdentity:'model-b',quantity:1},
  {title:'Model A',productCode:'SKU-1',invoiceProductIdentity:'model-a',quantity:2},
  {title:'Belirsiz',sku:'BARKOD',quantity:1},
 ];
 const html=ordersView({page:0,data:{total:1,items:[{id:1,provider:'n11',externalId:'1',products}]}});
 assert.equal((html.match(/class="shipment-product-code-line"/g)||[]).length,3);
 assert.match(html,/3 adet[\s\S]*<details[^>]+>[\s\S]*Model A/);
 assert.match(html,/Kod aktarılmadı/);
 assert.doesNotMatch(html,/BARKOD/);
});

test('Farklı SKU ve varyantlar ayrı kalır, bilinmeyen adet tahmin edilmez',()=>{
 const products=[{title:'Ürün',sku:'A',quantity:2},{title:'Ürün',sku:'B',quantity:1},{title:'Ürün',sku:'A',variantId:'blue',quantity:3},{title:'Ürün',sku:'A',quantity:null}];
 assert.deepEqual(summarizeOrderProducts(products),[{title:'Ürün',quantity:null},{title:'Ürün',quantity:1},{title:'Ürün',quantity:3}]);
 assert.equal(summarizeOrderProducts([{title:'Ürün',quantity:Number.MAX_SAFE_INTEGER},{title:'Ürün',quantity:1}])[0].quantity,null);
});
