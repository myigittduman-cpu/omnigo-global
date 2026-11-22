import test from 'node:test';import assert from 'node:assert/strict';
import {inventoryModelDefinitions} from '../public/inventory-model-definitions.js';
import {inventoryModeSetting} from '../public/inventory-mode-setting.js';
import {inventoryModel} from '../public/inventory-model.js';
test('Dört modelin kısa açıklaması seçilen modele aittir; mevcut kayıt kimlikleri korunur',()=>{
 assert.deepEqual(Object.keys(inventoryModelDefinitions),['horoz','both','store','marketplace']);
 for(const [key,model] of Object.entries(inventoryModelDefinitions)){
  const html=inventoryModeSetting(key);assert(html.includes(`value="${key}" selected`));assert(html.includes(`<small id="inventoryModelDescription">${model.description}</small>`));assert(model.description.length<230);assert(!/Horoz|Entegra/.test(model.label+model.description));assert.equal(inventoryModel(key).explanation,model.description);
 }
});
