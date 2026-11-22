import test from 'node:test';
import assert from 'node:assert/strict';
import {overviewSections,overviewTopResult} from '../public/overview-sections.js';
import {directoryPageNumbers,accountDirectoryPagination} from '../public/account-directory-pagination.js';
import {salesProductImage} from '../public/top-products.js';
import {canDeletePayment} from '../public/payment-delete.js';
test('Genel bakış aynı mağazada seçilen sekmeyi korur; yeni mağaza özete döner',()=>{
 const state={company:1,overviewSections:{company:1,tab:'sales'},data:{channels:[]}};
 const result=overviewSections(state,{summary:'ÖZET-İÇERİK',sales:'SATIŞ-İÇERİK'});
 assert.match(result,/data-overview-tab="sales" aria-selected="true"/);assert.match(result,/id="overview-panel-summary"[^>]*hidden/);
 assert.match(overviewSections({...state,company:2},{summary:'Özet',sales:'Satış'}),/data-overview-tab="summary" aria-selected="true"/);
});
test('Numaralı sayfalama uzun listede sınırlıdır ve ilk/son sayfayı korur',()=>{
 assert.deepEqual(directoryPageNumbers(1,1),[1]);assert.deepEqual(directoryPageNumbers(2,3),[1,2,3]);assert.deepEqual(directoryPageNumbers(50,100),[1,null,49,50,51,null,100]);
 assert.match(accountDirectoryPagination({page:2,pageCount:3,total:7}),/data-directory-goto="2"[^>]*aria-current="page" disabled/);
});
test('TOP 10 ürün görselini ve toplu miktarı aynı ürün üzerinde korur',()=>{
 const url='/api/sales/product-image?company=1&productKey='+'a'.repeat(64)+'&v='+'b'.repeat(48);
 const data={notes:[],products:[{sku:'A',title:'Ürün A',channel:'n11',netQuantity:2,netCents:100,imageUrl:url},{sku:'A',title:'Ürün A',channel:'Hepsiburada',netQuantity:3,netCents:150}]};
 const view=overviewTopResult(data);assert.match(view,/n11, Hepsiburada/);assert.match(view,/>5<\/td>/);assert.match(view,/product-image\?company=1/);
 assert.equal(salesProductImage('https://evil.test'+url),'');assert.equal(salesProductImage('/api/sales/product-image?company=1&productKey=other&v=other'),'');assert.equal(salesProductImage(url),url);
});
test('Müşteride ve otomatik arşiv kaydında silme düğmesi yoktur',()=>{
 assert.equal(canDeletePayment({id:4},{user:{role:'client'}}),false);assert.equal(canDeletePayment({id:4,integration:'n11'},{user:{role:'admin'}}),false);assert.equal(canDeletePayment({id:4},{user:{role:'admin'}}),true);
});
