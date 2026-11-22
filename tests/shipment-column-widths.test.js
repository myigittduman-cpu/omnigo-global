import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentColumnWidths} from '../public/shipment-column-widths.js';

const columns=[{minimum:84,preferred:84},{minimum:60,preferred:100},{address:true},{minimum:80,preferred:190},{address:true}];
test('İçeriğin ihtiyacı karşılanır, tüm kalan alan adreslere eşit verilir',()=>{
 const widths=shipmentColumnWidths(1000,columns);
 assert.deepEqual(widths,[84,100,313,190,313]);
});
test('Uzun metin daha geniş sütun alır; ekran daralınca toplam genişlik korunur',()=>{
 for(const width of [300,640,1025,1280,1920]){
  const sizes=shipmentColumnWidths(width,columns);
  assert.equal(sizes.reduce((sum,size)=>sum+size,0),width);
  assert.equal(sizes[0],84);assert.ok(sizes[3]>sizes[1]);
  assert.ok(Math.abs(sizes[2]-sizes[4])<=1);
 }
});
test('Kısa içerikte boş yer işlemlere değil adreslere aktarılır',()=>{
 const short=shipmentColumnWidths(1000,columns.map((column,index)=>index===3?{...column,preferred:90}:column));
 const long=shipmentColumnWidths(1000,columns);
 assert.equal(short[0],long[0]);assert.ok(short[2]>long[2]);assert.ok(short[3]<long[3]);
});
test('Dar ekranda uzun kelimeler sarılır; tutar korunur ve adresler ezilmez',()=>{
 const sizes=shipmentColumnWidths(960,[{minimum:84,preferred:84},{minimum:96,preferred:96},...Array.from({length:10},()=>({floor:42,minimum:75,preferred:100})),{address:true},{address:true}]);
 assert.equal(sizes[0],84);assert.equal(sizes[1],96);
 assert.ok(sizes.at(-1)>=96);assert.ok(sizes.at(-2)>=96);
 assert.equal(sizes.reduce((sum,size)=>sum+size,0),960);
});
