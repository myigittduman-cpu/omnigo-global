import test from 'node:test';
import assert from 'node:assert/strict';
import {canRefreshInventory,inventoryRefreshDelay} from '../public/inventory-refresh-policy.js';
const model=()=>({query:'',selected:new Set(),loading:false,busy:false});
test('Aktarım başlat veya satır düğmesinde kalan odak canlı yenilemeyi durdurmaz',()=>{
 for(const focusedTag of ['','BUTTON','DIV'])assert.equal(canRefreshInventory(model(),{focusedTag}),true);
});
test('Form, metin taslağı ve toplu seçim yenilemeden korunur',()=>{
 for(const focusedTag of ['INPUT','TEXTAREA','SELECT'])assert.equal(canRefreshInventory(model(),{focusedTag}),false);
 assert.equal(canRefreshInventory(model(),{dialogOpen:true}),false);
 assert.equal(canRefreshInventory(model(),{draftQuery:'Yazılmakta'}),false);
 assert.equal(canRefreshInventory({...model(),selected:new Set(['product'])}),false);
});
test('İlk stok ve ürün bilgisi aktarımı kısa aralıkla izlenir; tamamlanınca normal aralığa döner',()=>{
 assert.equal(inventoryRefreshDelay({activation:{marketplaceActive:true},initialImport:{complete:false}}),3000);
 assert.equal(inventoryRefreshDelay({activation:{marketplaceActive:true},initialImport:{complete:true},metadata:{pending:1}}),3000);
 assert.equal(inventoryRefreshDelay({activation:{marketplaceActive:true},initialImport:{complete:true},metadata:{pending:0}}),15000);
});
