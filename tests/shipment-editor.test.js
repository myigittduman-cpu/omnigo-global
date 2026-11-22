import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentEditorActions} from '../public/shipment-editor-view.js';
import {shipmentTable} from '../public/shipments-view.js';

const row={id:42,customerName:'Örnek Müşteri',provider:'n11',orderedAt:'2026-10-11T08:00:00Z'};
const model={canCreate:true,canConfirm:true,provider:'n11',city:'all',month:'2026-10',filters:{},data:{items:[row],page:2,pageSize:20,total:65,totalRecords:65}};
test('yeni kayıt yalnız yöneticide pazaryeri seçilince açılır',()=>{
 const button=value=>shipmentEditorActions(value).match(/<button[^>]*data-shipment-add[^>]*>/)?.[0];
 assert.ok(button(model));assert.match(button(model),/disabled/);
 assert.doesNotMatch(button({...model,editMode:true}),/disabled/);
 assert.doesNotMatch(button({...model,provider:'all'}),/disabled/);
 assert.match(button({...model,busy:true}),/disabled/);
 assert.equal(button({...model,canCreate:false}),undefined);
});
test('satır düzenleme ve silme yalnız düzenleme modunda, işlemler menüsünün dışında görünür',()=>{
 for(const canConfirm of [true,false]){
  assert.doesNotMatch(shipmentTable({...model,canConfirm}),/data-shipment-edit-row/);
  const html=shipmentTable({...model,canConfirm,editMode:true});
  const details=shipmentTable({...model,canConfirm}).match(/<details[^>]*data-shipment-operations="42"[\s\S]*?<\/details>/)?.[0];
  assert.ok(details);assert.doesNotMatch(details,/data-shipment-edit-row|data-shipment-delete/);
  assert.equal((html.match(/data-shipment-edit-row=/g)||[]).length,1);
 }
 const html=shipmentTable({...model,editor:{id:42,values:{customerName:'Örnek Müşteri'}}});
 assert.match(html,/data-shipment-row="42"[\s\S]*<\/tr><tr class="shipment-editor-row"/);
 assert.match(html,/type="submit" class="btn primary">Değişikliği ekle/);
});
test('kalıcı kayıt sonrası geri alma düğmesi gösterilmez',()=>{
 assert.doesNotMatch(shipmentEditorActions({...model,data:{...model.data,deleted:{id:43,version:2}}}),/Son silmeyi geri al/);
 assert.doesNotMatch(shipmentEditorActions({...model,data:{...model.data,deleted:null}}),/Son silmeyi geri al/);
});
