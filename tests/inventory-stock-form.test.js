import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryStockForm,stockPreview,parseStockAmount,marketplaceStockAmount} from '../public/inventory-stock-form.js';
import {inventorySku} from '../public/inventory-copy.js';
test('Güncel mağaza stoğu doğrudan atanır, satış ayrı hareket olarak kalır',()=>{
 const item={title:'Ürün',quantity:10};
 assert.match(inventoryStockForm(item),/name="mode" value="set"/);
 assert.doesNotMatch(inventoryStockForm(item),/Stok ekle|Stok azalt|<select|name="note"|type="number"/);
 assert.match(inventoryStockForm(item),/type="text" inputmode="numeric"/);
 assert.equal(stockPreview(item,'6'),'Kaydedilecek mağaza stoğu: 6 adet');
 assert.equal(stockPreview(item,'12',true),'Kaydedilecek mağaza stoğu: -2 adet');
 assert.match(inventoryStockForm({...item,quantity:null}),/value="initialize"/);
 assert.match(inventoryStockForm(item,true),/value="store_sale"/);
});

test('Elle girilen adet tam sayıdır; boş, ondalık, üstel ve aşırı değerler reddedilir',()=>{
 for(const value of ['', ' ', '1,5','1.5','1e3','2a','100000001'])assert.throws(()=>parseStockAmount(value));
 assert.equal(parseStockAmount(' 12 '),12);assert.equal(parseStockAmount('-4'),-4);assert.equal(parseStockAmount('0'),0);
 for(const value of ['0','-1'])assert.throws(()=>parseStockAmount(value,true));
 assert.equal(stockPreview({quantity:5},'1e2'), '');
});
test('Stok kodu güvenle gösterilir ve kopyalama simgesi üretilmez',()=>{
 assert.match(inventorySku('BD2076'),/>BD2076</);
 assert.doesNotMatch(inventorySku('BD2076'),/data-stock-copy|table-copy-button/);
 assert.doesNotMatch(inventorySku(''),/data-stock-copy/);
 assert.match(inventorySku('A"<x>'),/A&quot;&lt;x&gt;/);
});

test('Pazaryeri adedini kullanma yalnız doğrulanmış kaynaktan doldurur ve mağaza satışında görünmez',()=>{
 const item={title:'Ürün',quantity:null,marketplaceQuantity:7,marketplaceHealth:'current'};
 assert.equal(marketplaceStockAmount(item),7);assert.equal(marketplaceStockAmount({...item,marketplaceQuantity:0}),0);
 for(const change of [{marketplaceQuantity:null},{marketplaceQuantity:-1},{marketplaceHealth:'unverified'}])assert.equal(marketplaceStockAmount({...item,...change}),null);
 assert.match(inventoryStockForm(item),/Pazaryeri stoğuyla aynı · 7 adet/);
 assert.match(inventoryStockForm({...item,quantity:4}),/data-stock-use-marketplace/);
 assert.doesNotMatch(inventoryStockForm(item,true),/data-stock-use-marketplace/);
 assert.match(inventoryStockForm({...item,marketplaceQuantity:null}),/data-stock-use-marketplace disabled/);
});
