import test from 'node:test';
import assert from 'node:assert/strict';
import {productPageData,reportProducts} from '../public/report-products.js';
import {reportTables} from '../public/report-export-data.js';

const products=Array.from({length:16},(_,index)=>({channel:'Koçtaş',sku:String(index+1),title:'Ürün '+(index+1),netQuantity:1,netCents:100,grossCents:100,cancelCents:0,returnCents:0}));

test('Eski sayfa yardımcısı veri kaybetmez; güncel liste ilk 10 ürünü tek tabloda gösterir',()=>{
 const pages=[1,2,3,4].map(page=>productPageData(products,page));
 assert.deepEqual(pages.map(page=>page.items.length),[5,5,5,1]);
 assert.deepEqual(pages.flatMap(page=>page.items),products);
 assert.deepEqual([pages[3].from,pages[3].to,pages[3].total],[16,16,16]);
 const html=reportProducts(products,1);
 assert.equal((html.match(/<tr>/g)||[]).length,11);
 assert.match(html,/En çok satanlar/);assert.doesNotMatch(html,/data-product-page/);
 assert.equal(reportProducts(products,4),html);
});

test('Boş, tek sayfalık ve küçülen sonuçlar geçerli sayfaya döner',()=>{
 for(const count of [0,1,5]){
  const page=productPageData(products.slice(0,count),4);
  assert.equal(page.page,1);
  assert.equal(page.totalPages,1);
  assert.ok(!reportProducts(page.items).includes('<nav'));
 }
 assert.equal(productPageData(products.slice(0,6),4).page,2);
 for(const value of [-1,0,NaN,Infinity,'geçersiz'])assert.equal(productPageData(products,value).page,1);
 assert.match(reportProducts([]),/satış adedi doğrulanmış ürün bulunmuyor/);
});

test('Uzun raporda kaynak korunur ve yalnız ilk 10 satır gösterilir',()=>{
 const many=Array.from({length:5000},(_,index)=>({...products[0],sku:String(index)}));
 assert.equal(productPageData(many,1000).items.at(-1).sku,'4999');
 const html=reportProducts(many,500);
 assert.ok((html.match(/data-product-page=/g)||[]).length<=9);
 assert.equal((html.match(/<tr>/g)||[]).length,11);
 assert.doesNotMatch(html,/data-product-page|aria-current/);
 assert.equal(many.length,5000);
});

test('Sayfalama HTML metinlerini kaçırır ve dışa aktarılan tam listeyi değiştirmez',()=>{
 assert.ok(!reportProducts([{...products[0],title:'<img onerror="x">'}]).includes('<img'));
 const report={company:{name:'Örnek'},from:'2026-10-01',to:'2026-10-10',previousFrom:'2026-09-01',previousTo:'2026-09-10',generatedAt:'2026-10-10',availableChannels:1,selectedChannels:1,totals:{netCents:1600,orders:16,averageCents:100},previousTotals:null,series:[],notes:[],products,payments:{items:[]}};
 reportProducts(report.products,4);
 assert.equal(reportTables(report)[2].rows.length,10);
 assert.equal(report.products.length,16);
});
