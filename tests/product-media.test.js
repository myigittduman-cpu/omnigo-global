import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {curatedProductImage,productMediaCatalog} from '../public/product-media.js';
import {productImage} from '../public/top-products.js';

test('Resmî görsel yalnızca doğrulanan marka ve tam model koduyla eşleşir',()=>{
 const image=curatedProductImage({sku:' cga242x3tr ',title:'Profilo Çamaşır Makinesi'});
 assert.equal(image.imageUrl,'/assets/products/profilo/cga242x3tr.webp');
 for(const sku of ['CGA242X3TR-X','BMS623V5','../CGA242X3TR','constructor'])assert.equal(curatedProductImage({sku,title:'Profilo'}),null);
 assert.equal(curatedProductImage({sku:'CGA242X3TR',title:'Başka marka'}),null);
 assert.ok(curatedProductImage({sku:'BMS623V1',title:'BMS623V1 6 Programlı Bulaşık Makinesi'}));
 assert.equal(curatedProductImage({sku:'BMS623V1',title:'BMS623V1 6 Programlı Bulaşık Makinesi',brand:'Başka marka'}),null);
 assert.equal(curatedProductImage({sku:'BMS623V1',title:'BMS623V1 farklı ürün'}),null);
 assert.match(productImage({sku:'CGA242X3TR',title:'Profilo',imageUrl:'https://example.test/old.jpg'}),/src="\/assets\/products\/profilo\/cga242x3tr.webp"/);
 assert.match(productImage({sku:'OTHER',title:'Diğer ürün',imageUrl:'https://example.test/photo.jpg'}),/src="https:\/\/example.test\/photo.jpg"/);
});
test('Katalogdaki görseller gerçek WebP dosyalarına ve resmî kaynaklara bağlıdır',()=>{
 for(const image of Object.values(productMediaCatalog)){
  assert.equal(new URL(image.source).hostname,'www.profilo.com');
  assert.equal(new URL(image.original).hostname,'media3.bsh-group.com');
  const bytes=fs.readFileSync(new URL('../public'+image.imageUrl,import.meta.url));
  assert.equal(bytes.toString('ascii',8,12),'WEBP');
  assert.ok(bytes.length>10000&&image.height>=2800);
 }
});
