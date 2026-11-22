import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryPackageLabels,missingPackageBarcode} from '../public/delivery-package-barcodes.js';

test('Her paketin kaynak kodu ayrı kalır ve baştaki sıfırlar korunur',()=>{
 const order={shippingLabels:[
  {packageId:'001',value:'00001111222239',format:'CODE128'},
  {packageId:'002',value:'Ab_cD.09-001',format:'CODE128'},
  {packageId:'003',value:null,format:'CODE128'}
 ]};
 const [first,second,third]=deliveryPackageLabels(order);
 assert.equal(first.value,'00001111222239');
 assert.equal(second.value,'Ab_cD.09-001');
 assert.deepEqual(third,{caption:'Paket no: 003',value:null});
 assert.deepEqual([first.caption,second.caption],['Paket no: 001','Paket no: 002']);
 for(const label of [first,second]){
  const last=label.bars.at(-1);
  assert.equal(label.bars[0].start,12);
  assert.equal(label.modules-last.start-last.length,12);
 }
 assert.notDeepEqual(first.bars,second.bars);
});

test('Eski veya eksik etiketin yerine sipariş, ürün ve takip numarası basılmaz',()=>{
 const order={orderNumber:'000123',products:[{sku:'SKU-1',barcode:'001122'}],trackingNumber:'0099'};
 const missing=[{caption:'Paket barkodu',value:null}];
 assert.deepEqual(deliveryPackageLabels(order),missing);
 assert.deepEqual(deliveryPackageLabels({...order,shippingLabels:[]}),missing);
 for(const [value,format] of [['1234','QR'],[1234,'CODE128'],['A'.repeat(49),'CODE128'],['0012\n','CODE128']]){
  assert.deepEqual(deliveryPackageLabels({...order,shippingLabels:[{value,format}]}),missing);
 }
 assert.equal(missingPackageBarcode(),'Pazaryerinden paket barkodu gelmedi');
 assert.equal(deliveryPackageLabels({shippingLabels:[{value:'Z'.repeat(48),format:'CODE128'}]})[0].value,'Z'.repeat(48));
});
