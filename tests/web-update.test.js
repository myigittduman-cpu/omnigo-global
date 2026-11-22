import test from 'node:test';
import assert from 'node:assert/strict';
import {UPDATE_STORAGE_KEY,readPendingUpdate,rememberUpdate,releaseAtLeast} from '../public/web-update-state.js';
import {updateWorkInProgress} from '../public/web-update.js';
test('Güncelleme yalnız geçerli, güncel sürüm bilgisini taşır; eski veya bozuk metadata açılışı engellemez',()=>{
 const values=new Map(),storage={getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value)};
 rememberUpdate(storage,'0.9.271',1000);assert.deepEqual(readPendingUpdate(storage,2000),{version:'0.9.271',createdAt:1000});
 assert.equal(readPendingUpdate(storage,901000),null);assert.equal(readPendingUpdate(storage,999),null);
 assert.throws(()=>rememberUpdate(storage,'https://example.invalid',1000));
 assert.deepEqual(Object.keys(JSON.parse(values.get(UPDATE_STORAGE_KEY))).sort(),['createdAt','version']);
 values.set(UPDATE_STORAGE_KEY,'invalid');assert.equal(readPendingUpdate(storage),null);
 assert.equal(readPendingUpdate({getItem(){throw Error('Storage unavailable');}}),null);
});
test('Eksik sürüm başarı sayılmaz; yeni ara sürüm de hedef güncellemeyi karşılar',()=>{
 assert.equal(releaseAtLeast('0.9.271','0.9.271'),true);assert.equal(releaseAtLeast('0.10.0','0.9.271'),true);
 assert.equal(releaseAtLeast('0.9.270','0.9.271'),false);assert.equal(releaseAtLeast(undefined,'0.9.271'),false);
});
test('Güncelleme penceresi aktif form, dosya yükleme veya sevkiyat taslağını yarıda kesmez',()=>{
 const original=globalThis.document;let dialogs=[],forms=[],shipment=null;
 globalThis.document={querySelectorAll:selector=>selector==='dialog[open]'?dialogs:forms,querySelector:()=>shipment};
 try{
  assert.equal(updateWorkInProgress(),false);
  dialogs=[{id:'dialog'}];assert.equal(updateWorkInProgress(),true);
  dialogs=[{id:'webUpdateDialog'}];assert.equal(updateWorkInProgress(),false);
  shipment={shipmentUpdateBlocked:()=>true};assert.equal(updateWorkInProgress(),true);shipment=null;
  forms=[{elements:[{type:'text',value:'Taslak',defaultValue:''}]}];assert.equal(updateWorkInProgress(),true);
  forms=[{elements:[{type:'file',files:[{}]}]}];assert.equal(updateWorkInProgress(),true);
  forms=[{elements:[{type:'text',value:'Aynı',defaultValue:'Aynı'}]}];assert.equal(updateWorkInProgress(),false);
 }finally{globalThis.document=original;}
});
