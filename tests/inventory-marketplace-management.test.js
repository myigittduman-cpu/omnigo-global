import test from 'node:test';import assert from 'node:assert/strict';
import {inventoryModeSelection,inventoryModeValue,managedInventoryMode} from '../public/inventory-management-model.js';
import {inventoryModeSetting} from '../public/inventory-mode-setting.js';
import {inventoryRowActions} from '../public/inventory-row-actions.js';
import {marketplaceStockForm} from '../public/inventory-marketplace-stock.js';
import {inventoryModel} from '../public/inventory-model.js';
const option={baseMode:'horoz',label:'Canlı stok + manuel işlem + pazaryeri yönetimi',description:'Mevcut hesap korunur.'};
test('Yeni stok modeli mevcut dört hesabı değiştirmeden bağımsız açılır ve geri alınır',()=>{
 for(const mode of ['horoz','both','store','marketplace'])assert.deepEqual(inventoryModeSelection(mode,option),{mode});
 assert.deepEqual(inventoryModeSelection(managedInventoryMode,option),{mode:'horoz',marketplaceManagement:true});
 assert.equal(inventoryModeValue({mode:'horoz',marketplaceManagement:true},option),managedInventoryMode);
 assert.throws(()=>inventoryModeSelection(managedInventoryMode,null));
 assert.equal((inventoryModeSetting('horoz').match(/<option/g)||[]).length,4);
 assert.equal((inventoryModeSetting('horoz',option,{mode:'horoz',marketplaceManagement:true}).match(/<option/g)||[]).length,5);
});
test('Beşinci etiket yalnız açıkça seçildiğinde görünür; eski modelin sütun hesabı değişmez',()=>{
 const old=inventoryModel('horoz'),managed=inventoryModel('horoz',{marketplaceManagement:true});
 assert.equal(old.summary,'Canlı stok + manuel işlem');assert.equal(managed.summary,'Canlı stok + manuel işlem + pazaryeri yönetimi');
 for(const key of ['horoz','store','online','showStore','showTotal','stockColumns'])assert.equal(old[key],managed[key]);
 for(const mode of ['horoz','both','store','marketplace'])assert.doesNotMatch(inventoryModel(mode).summary,/pazaryeri yönetimi/);
});
test('Gönderim düğmesi yalnız yöneticiye ve kesin hedefi olan ürüne görünür',()=>{
 const item={key:'a'.repeat(64),quantity:5,marketplaceStockTargets:[{key:'b'.repeat(64),provider:'Hepsiburada',sku:'SKU-A',quantity:7}]};
 assert.match(inventoryRowActions(item,true,{store:true,online:true}),/data-marketplace-stock=/);
 assert.doesNotMatch(inventoryRowActions(item,false,{store:true,online:true}),/data-marketplace-stock=/);
 assert.doesNotMatch(inventoryRowActions({...item,removed:true},true,{store:true,online:true}),/data-marketplace-stock=/);
 assert.doesNotMatch(inventoryRowActions({...item,marketplaceStockTargets:[]},true,{store:true,online:true}),/data-marketplace-stock=/);
});
test('Gönderim formu ürünü, pazaryerini ve onayı açık gösterir; hücre metinlerini HTML olarak işlemez',()=>{
 const markup=marketplaceStockForm({title:'<Ürün>',sku:'SKU-A',quantity:5,marketplaceStockTargets:[{key:'b'.repeat(64),provider:'Hepsiburada',sku:'<SKU>',quantity:7}]});
 assert.match(markup,/&lt;Ürün&gt;/);assert.match(markup,/&lt;SKU&gt;/);assert.match(markup,/name="confirmed" required/);assert.match(markup,/autocomplete="off"/);assert.match(markup,/sonuç pazaryerinden doğrulanır/);assert.doesNotMatch(markup,/Price|dispatchTime|maximumPurchasableQuantity/);
});
