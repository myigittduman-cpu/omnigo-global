import test from 'node:test';
import assert from 'node:assert/strict';
import {deliveryView} from '../public/delivery-view.js';
import {refreshedDeliverySelection} from '../public/delivery-live-selection.js';
import {deliverySelectionEntries,selectPageDeliveries} from '../public/delivery-dispatch-selection.js';
import {shipmentTable,shipmentDisplayColumns} from '../public/shipments-view.js';
const card=(id,state)=>({id,version:1,dispatchState:state,selectionKey:`${id}:${state}`,fulfillmentStatus:state,city:'Ankara',active:true,issues:[],products:[{title:'Test',quantity:1}],provider:'n11'});
const items=[card(1,'pending'),card(1,'shipped'),card(2,'shipped'),card(3,'pending')];
const model={queue:'all',city:'Ankara',date:'2026-10-26',selected:new Set(),manualSelection:false,data:{items,cities:[],sync:{}}};
test('both shipment statuses open selected and refresh preserves explicit choices',()=>{
 assert.deepEqual([...refreshedDeliverySelection(model,items)],['1:pending','1:shipped','2:shipped','3:pending']);
 assert.deepEqual([...selectPageDeliveries(items,true)],['1:pending','1:shipped','2:shipped','3:pending']);
 assert.deepEqual([...refreshedDeliverySelection({...model,manualSelection:true,selected:new Set(['1:shipped'])},items)],['1:shipped']);
 assert.deepEqual([...refreshedDeliverySelection({...model,manualSelection:true,selected:new Set(['2:pending'])},[card(2,'shipped')])],[]);
 assert.deepEqual([...selectPageDeliveries(items,false)],[]);
});
test('the selected order exports all of its current package versions',()=>{
 const packed={...items[0],selectionRecords:[{id:1,version:1},{id:8,version:2}]};
 assert.deepEqual(deliverySelectionEntries([packed,items[2]],new Set(['1:pending'])),[{id:1,version:1},{id:8,version:2}]);
 assert.deepEqual(deliverySelectionEntries(items,new Set(['1:pending','1:shipped','2:shipped'])),[{id:1,version:1},{id:2,version:1}]);
 const html=deliveryView({...model,includeShipped:true,selected:new Set(['1:pending','1:shipped'])});
 assert.match(html,/Toplam: 1 sipariş · 2 parça ürün/);assert.doesNotMatch(html,/data-include-shipped/);
 assert.match(html,/data-create[\s\S]*data-delivery-print[\s\S]*data-delivery-share[\s\S]*data-excel/);
 assert.doesNotMatch(html,/Sevkiyat Onayı|data-delivery-additions/);
 assert.match(html,/data-order="2:shipped"\s*>/);assert.doesNotMatch(html,/Evrağa yalnız elle seçilirse eklenir/);
});
test('selection totals count split packages once per order and exclude unselected shipped orders',()=>{
 const selectedItems=Array.from({length:17},(_,index)=>({...card(index+1,index<8?'pending':'shipped'),orderNumber:String(index<3?100:100+index)}));
 const selected=new Set(selectedItems.map(order=>order.selectionKey));
 const data={...model.data,items:[...selectedItems,{...card(99,'shipped'),orderNumber:'999',products:[{title:'Not selected',quantity:4}]}]};
 const html=deliveryView({...model,includeShipped:true,selected,data});
 assert.match(html,/<span data-selection-count>Toplam: 15 sipariş · 17 parça ürün<\/span>/);
 selected.delete(selectedItems[0].selectionKey);
 assert.match(deliveryView({...model,includeShipped:true,selected,data}),/Toplam: 15 sipariş · 16 parça ürün/);
 assert.match(deliveryView({...model,selected:new Set(),data}),/Toplam: 0 sipariş · 0 parça ürün/);
});
test('customer shipment table keeps columns, approved copy fields and filters without mutation controls',()=>{
 const row={id:1,version:1,customerName:'Test',recipientName:'Alıcı',phone:'0500',productName:'Test',rowStatus:'shipped'};
 const base={month:'2026-10',provider:'n11',city:'all',data:{items:[row],total:1,totalRecords:1,page:0,pageSize:50},filters:{},selectedIds:new Set(),draftChanges:[],canCreate:true,canShare:true};
 const client=shipmentTable({...base,readOnly:true}),admin=shipmentTable({...base,readOnly:false});
 assert.equal((client.match(/<th scope="col"/g)||[]).length,shipmentDisplayColumns().length);
 assert.equal((client.match(/<td /g)||[]).length,shipmentDisplayColumns().length);
 assert.match(client,/data-shipment-copy="recipientName"/);assert.match(client,/data-shipment-search/);assert.match(client,/data-shipment-city-filter/);
 assert.match(client,/status-shipped/);
 assert.doesNotMatch(client,/data-shipment-(?:edit|delete|document|status-save|check|select|export|share|add)/);
 assert.match(admin,/data-shipment-operations=/);assert.match(admin,/data-shipment-select=/);assert.match(admin,/data-shipment-export=/);
});
