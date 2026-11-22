import test from 'node:test';
import assert from 'node:assert/strict';
import {stageShipmentChange,shipmentDraftRows} from '../public/shipment-edit-session.js';
import {shipmentEditorActions} from '../public/shipment-editor-view.js';
import {shipmentOperations} from '../public/shipment-operations.js';
test('Taslak değişiklikler kaynağı değiştirmez; son düzenleme tek kayıtta, işlem geçmişi sıralı kalır',()=>{
 const source={id:1,customerName:'Örnek',productName:'Ürün',version:2,invoiceCents:100};
 const model={data:{items:[source]},provider:'n11'};
 stageShipmentChange(model,{type:'update',id:1,version:2,record:{customerName:'Bir',invoiceAmount:'12,50',orderedAt:'2026-10-20T10:00'}},'İlk düzenleme');
 stageShipmentChange(model,{type:'update',id:1,version:2,record:{customerName:'İki',invoiceAmount:'12,50',orderedAt:'2026-10-20T10:00'}},'İkinci düzenleme');
 assert.equal(model.draftChanges.length,1);assert.deepEqual(model.draftLog,['İlk düzenleme','İkinci düzenleme']);
 assert.equal(shipmentDraftRows(model)[0].customerName,'İki');assert.equal(source.customerName,'Örnek');
 stageShipmentChange(model,{type:'delete',id:1,version:2},'Silme');
 assert.equal(shipmentDraftRows(model).length,0);assert.equal(source.version,2);
});
test('Tüm pazaryerlerinde ekleme formu yerine uyarı düğmesi; diğerlerinde Düzenle modu gerekir',()=>{
 const base={canCreate:true,provider:'all'};
 assert.match(shipmentEditorActions(base),/data-shipment-add >/);
 assert.match(shipmentEditorActions({...base,provider:'n11'}),/data-shipment-add disabled/);
 assert.match(shipmentEditorActions({...base,provider:'n11',editMode:true}),/data-shipment-add >/);
 assert.doesNotMatch(shipmentEditorActions({...base,data:{undo:{id:1},deleted:{id:2}}}),/data-shipment-undo|data-shipment-restore/);
 assert.doesNotMatch(shipmentOperations({id:1,provider:'n11'}),/data-shipment-edit-row|data-shipment-delete/);
});
