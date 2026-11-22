import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentRow} from '../public/shipments-view.js';
import {shipmentStatusLabel} from '../public/shipment-status.js';
import {shipmentTableAmountCents} from '../public/table-amounts.js';
import {activeShipmentExport} from '../public/shipment-export-rows.js';

test('Eski tam iade işareti kalan adetten önce gelir, bilinmeyen işaret etkin siparişi silmez',()=>{
 const active={id:1,provider:'n11',orderNumber:'ETKIN',quantity:5,invoiceCents:50000,fulfillmentStatus:'returned',marketplaceReturn:{partial:null,returnedQuantity:null}};
 const rows=[active,{...active,fulfillmentStatus:'pending',id:2,orderNumber:'KISMI',marketplaceReturn:{quantity:5,partial:true,returnedQuantity:1}},
  ...[false,0,'0','false'].map((partial,i)=>({...active,fulfillmentStatus:'pending',id:3+i,orderNumber:'TAM-'+i,marketplaceReturn:{partial,quantity:5,returnedQuantity:1,remainingQuantity:4}}))];
 const result=activeShipmentExport({items:rows});
 assert.deepEqual(result.items.map(row=>[row.orderNumber,row.quantity,row.invoiceCents]),[['ETKIN',5,50000],['KISMI',4,null]]);
 assert.deepEqual(result.summary,{orderCount:2,productCount:9});
});
test('Onaylı kısmi iade kırmızı; adet açıklaması doğru, tutar ve iptal verisi aynı',()=>{
 const row={id:1,provider:'n11',customerName:'Örnek',quantity:3,invoiceCents:30000,rowStatus:'shipped',marketplaceReturn:{quantity:3,returnedQuantity:1,remainingQuantity:2,partial:true}};
 const before=structuredClone(row),html=shipmentRow(row);
 assert.match(html,/shipment-row-cancelled/);assert.match(html,/İade: 1<br>Kalan: 2/);assert.match(html,/Kısmi iade · 1 adet/);
 assert.equal(shipmentTableAmountCents(row),30000);assert.deepEqual(row,before);
 assert.equal(shipmentStatusLabel({...row,marketplaceReturn:{...row.marketplaceReturn,partial:false,remainingQuantity:0}}),'İade onaylandı');
 assert.doesNotMatch(shipmentRow({...row,marketplaceReturn:null}),/shipment-row-cancelled/);
});
