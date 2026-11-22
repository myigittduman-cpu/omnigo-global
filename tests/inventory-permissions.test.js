import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryActions,inventoryCapabilities} from '../public/inventory-capabilities.js';
import {inventoryView} from '../public/inventory-view.js';

const item={key:'product-7',title:'Ürün',sku:'SKU',quantity:10,storeQuantity:3,marketplaceQuantity:10,totalQuantity:13,purchasePriceCents:3061122,marketplaceStocks:[{provider:'n11',quantity:10}]};
const grants=enabled=>Object.fromEntries(inventoryActions.map(action=>[action,enabled.includes(action)]));
const state=permissions=>({user:{role:'client'},data:{workspaceAccess:{pages:{inventory:'shown'},grants:{inventory:permissions}}}});
const render=(permissions,mode='horoz')=>inventoryView({admin:false,capabilities:permissions,query:'',filter:'stocked',data:{settings:{mode},items:[item]}});
test('Müşteri izinleri birbirinden ayrıdır; sunucu reddi ve gizli bölüm her zaman üstün gelir',()=>{
 const rights=grants(inventoryActions);assert.deepEqual(inventoryCapabilities(state(rights),rights),rights);
 const noPrice={...rights,editPurchasePrice:false};assert.equal(inventoryCapabilities(state(rights),noPrice).editPurchasePrice,false);
 const hidden=state(rights);hidden.data.workspaceAccess.pages.inventory='hidden';assert(Object.values(inventoryCapabilities(hidden,rights)).every(value=>value===false));
 assert(Object.values(inventoryCapabilities({user:{role:'admin'}},grants([]))).every(Boolean));
});
test('Salt görüntüleme alış fiyatını ve tüm işlem düğmelerini kaldırır; satır/sütun sayısı korunur',()=>{
 for(const mode of ['horoz','both','store','marketplace']){
  const html=render(grants(['view']),mode);assert.doesNotMatch(html,/30\.611|Alış fiyatı|data-purchase-price|data-stock-edit|data-stock-history|data-stock-sales|data-stock-sources|data-store-sale|inventory-actions-column/);
  assert.equal((html.match(/<th scope=/g)||[]).length,(html.match(/<td role=/g)||[]).length);
 }
});
test('Alış fiyatı salt okunur olabilir; düzenleme yalnız kendi ürün anahtarını açar',()=>{
 const read=render(grants(['view','viewPurchasePrice']));assert.match(read,/30\.611,22/);assert.doesNotMatch(read,/data-purchase-price/);
 assert.match(render(grants(['view','viewPurchasePrice','editPurchasePrice'])),/data-purchase-price="product-7"/);
});
test('Her stok işlemi yalnız kendi izniyle açılır; pazaryeri modelinde manuel yazma düğmesi oluşmaz',()=>{
 for(const [action,selector] of [['viewMovements','data-stock-history'],['viewSales','data-stock-sales'],['viewMarketplaceStocks','data-stock-sources'],['editStock','data-stock-edit'],['recordStoreSale','data-store-sale']]){
  const html=render(grants(['view',action]));assert.ok(html.includes(selector),action);
  for(const other of ['data-stock-edit','data-store-sale','data-stock-sales','data-stock-sources','data-stock-history'])if(other!==selector)assert(!html.includes(other),`${action}: ${other}`);
 }
 const online=render(grants(inventoryActions),'marketplace');assert.doesNotMatch(online,/data-stock-edit|data-store-sale|data-manual-add|data-stock-bulk/);
});
