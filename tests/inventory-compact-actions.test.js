import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryRowActions} from '../public/inventory-row-actions.js';
import {inventorySalesDetails} from '../public/inventory-sales-details.js';
import {persistentInventoryErrors,inventoryErrorSources} from '../public/inventory-retry-state.js';

test('Silme satırda tekrarlanmaz; mağaza satışı yalnız sayımdan sonra açılır',()=>{
 const item={key:'one',storeQuantity:null,marketplaceStocks:[{}]};
 const html=inventoryRowActions(item,true,{store:true,online:true});
 assert.equal((html.match(/<button/g)||[]).length,5);
 assert.doesNotMatch(html,/stock-remove|Tablodan sil/);
 assert.match(html,/data-store-sale="one" disabled/);
 assert.match(html,/data-stock-sales="one"/);
 assert.match(inventoryRowActions({...item,storeQuantity:0},true,{store:true,online:true}),/data-store-sale/);
 assert.doesNotMatch(inventoryRowActions({...item,storeQuantity:0},true,{store:true,online:true}),/data-store-sale="one" disabled/);
 assert.doesNotMatch(inventoryRowActions(item,false,{store:true,online:true}),/data-stock-edit|data-store-sale/);
});
test('Satış ayrıntısı pazaryerlerini ve gerçek adetleri korur',()=>{
 const html=inventorySalesDetails({title:'<Ürün>',storeSales:7,listedOn:['n11','PTT AVM'],markets:[{provider:'n11',gross:12,cancelled:2,returned:1,net:9,averageInvoiceCents:123456}]});
 assert.match(html,/&lt;Ürün&gt;/);assert.match(html,/PTT AVM/);
 assert.match(html,/<td>12<\/td><td>2<\/td><td>1<\/td><td>9<\/td>/);
 assert.match(html,/1\.234,56/);
 assert.match(html,/<th scope="row">Mağaza<\/th><td>7<\/td><td>—<\/td><td>—<\/td><td>7<\/td>/);
});
test('Geçici hata gizli kalır; kesintili denemede beş dakika sonra gösterilir ve başarılı aktarımda kapanır',()=>{
 const failed={provider:'n11',state:'retry',error:'timeout',lastSuccess:'old'};
 let result=persistentInventoryErrors({},[failed],0);assert.equal(result.visible,false);
 result=persistentInventoryErrors(result.state,[{...failed,state:'running',error:null}],299999);assert.equal(result.visible,false);
 result=persistentInventoryErrors(result.state,[failed],300000);assert.equal(result.visible,true);
 result=persistentInventoryErrors(result.state,[{...failed,state:'complete',error:null,lastSuccess:'new'}],300001);
 assert.equal(result.visible,false);assert.deepEqual(result.state,{});
});

test('Stok hesaplama işi de aynı gecikme ve iyileşme kuralına uyar',()=>{
 const sources=inventoryErrorSources({progress:{providers:[{provider:'n11',state:'complete'}]},sync:{error:'Geçici kesinti',lastSuccess:'old'}});
 let result=persistentInventoryErrors({},sources,0);assert.equal(result.visible,false);
 result=persistentInventoryErrors(result.state,sources,300000);assert.equal(result.visible,true);
 result=persistentInventoryErrors(result.state,inventoryErrorSources({sync:{error:null,lastSuccess:'new'}}),300001);
 assert.equal(result.visible,false);
});
