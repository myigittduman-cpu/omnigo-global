import test from 'node:test';
import assert from 'node:assert/strict';
import {inventoryView} from '../public/inventory-view.js';

for(const admin of [true,false])test(`Trendyol stok filtresi ortak kaynak listesinden gelir; admin=${admin}`,()=>{
 const providers=['Hepsiburada','n11','idefix','Pazarama','PTT AVM','Koçtaş','Trendyol'];
 const html=inventoryView({admin,query:'',filter:'all',provider:'Trendyol',data:{items:[],settings:{mode:'horoz'},providers}});
 const select=html.match(/<select name="provider"[\s\S]*?<\/select>/)?.[0];
 assert.ok(select);assert.match(select,/<option value="Trendyol" selected>Trendyol<\/option>/);
 assert.equal((select.match(/<option /g)||[]).length,8);
 for(const provider of providers)assert.ok(select.includes(`value="${provider}"`));
 assert.match(select,/<option value="">Tüm pazaryerleri<\/option>/);
});

test('Pazaryeri filtresi yenilenirken seçim ve bekleme kilidi aynı düzende korunur',()=>{
 const html=inventoryView({admin:true,busy:true,query:'',filter:'all',provider:'Trendyol',data:{items:[],settings:{mode:'horoz'},providers:['Trendyol']}});
 assert.match(html,/<select name="provider" disabled>/);
 assert.match(html,/<option value="Trendyol" selected>Trendyol<\/option>/);
});
