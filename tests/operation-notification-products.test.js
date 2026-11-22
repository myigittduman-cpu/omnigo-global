import test from 'node:test';import assert from 'node:assert/strict';
import {operationNotificationDetail} from '../public/operation-notification.js';
test('operation details show scoped products, quantity, invoice and claim amounts safely',()=>{
 const output=operationNotificationDetail({events:['return_updated'],provider:'n11',quantity:1,claimAmountCents:50000,products:[{title:'<Ürün>',sku:'CODE',quantity:1,invoiceCents:100000}]});
 assert.match(output,/&lt;Ürün&gt;/);assert.match(output,/CODE/);assert.match(output,/Adet/);assert.match(output,/Fatura satır tutarı/);assert.match(output,/Talep tutarı/);assert.match(output,/geri ödemenin tamamlandığı anlamına gelmez/);
});
test('unknown products and amounts are never presented as zero',()=>{
 const output=operationNotificationDetail({events:['cancelled'],products:[{title:'Ürün',quantity:null,invoiceCents:null}]});assert.match(output,/Teyit bekleniyor/);assert.match(output,/Aktarılmadı/);assert.doesNotMatch(output,/₺0/);
});
