import test from 'node:test';
import assert from 'node:assert/strict';
import {orderTableAmountView,shipmentTableAmountCents} from '../public/table-amounts.js';
import {ordersView} from '../public/orders-view.js';
import {shipmentRow} from '../public/shipments-view.js';
import {shipmentCopyValue} from '../public/shipment-copy.js';
import {shipmentExportValue,createShipmentWorkbook} from '../public/shipment-export.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';

const base={id:1,provider:'Hepsiburada',createdAt:'2026-10-22T10:00:00+03:00',grossCents:123456,cancelCents:123456,returnCents:0,products:[]};
test('İptal tutarı tüm pazaryerlerinde gizlenir; kaynak değerler ve kırmızı durum korunur',()=>{
 for(const provider of ['Hepsiburada','n11','Pazarama','idefix','Koçtaş','PTT AVM']){
  const order=Object.freeze({...base,provider}),before=JSON.stringify(order);
  assert.equal(orderTableAmountView(order),'—');
  const html=ordersView({page:0,from:'2026-10-01',to:'2026-10-22',data:{items:[order],total:1}});
  assert.match(html,/order-cancelled/);assert.match(html,/data-label="Tutar">—<\/td>/);
  assert.doesNotMatch(html,/1\.234,56|İptal:|Kalan:|Tutar dökümü/);
  assert.equal(JSON.stringify(order),before);
 }
});
test('Kısmi iptalde kalan ve iade tutarı ayrı gösterilir; açık sipariş değişmez',()=>{
 const partial=Object.freeze({...base,cancelCents:23456,returnCents:10000});
 assert.match(orderTableAmountView(partial),/1\.000,00/);
 assert.match(orderTableAmountView(partial),/İade:.*100,00/);
 assert.match(orderTableAmountView(partial),/Kalan:.*900,00/);
 assert.doesNotMatch(orderTableAmountView(partial),/1\.234,56|234,56/);
 assert.equal(partial.grossCents-partial.cancelCents-partial.returnCents,90000);
 assert.match(orderTableAmountView({...base,cancelCents:0}),/1\.234,56/);
 assert.equal(orderTableAmountView({...base,grossCents:0,cancelCents:0,cancellation:{quantity:1,cancelledQuantity:1,partial:false}}),'—');
});
test('Sevkiyat iptal tutarı hücre, pano ve dışa aktarımda gizlenir; kaydı silmez',async()=>{
 const cancelled=Object.freeze({id:1,orderedAt:'2026-10-22T10:00:00+03:00',invoiceCents:123456,quantity:1,rowStatus:'cancelled',recipientName:'Örnek',orderNumber:'TEST'});
 const active=Object.freeze({...cancelled,id:2,rowStatus:'shipped',orderNumber:'TEST-ACTIVE'}),before=JSON.stringify([cancelled,active]);
 assert.equal(shipmentTableAmountCents(cancelled),null);
 assert.equal(shipmentCopyValue(cancelled,'invoiceCents'),'');assert.equal(shipmentExportValue(cancelled,'invoiceCents'),null);
 assert.match(shipmentRow(cancelled),/shipment-row-cancelled/);
 assert.doesNotMatch(shipmentRow(cancelled),/1\.234,56|data-shipment-copy="invoiceCents"/);
 assert.equal(shipmentTableAmountCents(active),123456);assert.equal(shipmentExportValue(active,'invoiceCents'),1234.56);
 const workbook=new Workbook();
 await workbook.xlsx.load(await (await createShipmentWorkbook({provider:'all',city:'all',month:'2026-10',total:2,items:[cancelled,active]})).arrayBuffer());
 const sheet=workbook.getWorksheet(1);
 assert.equal(sheet.getCell('H8').value,1234.56);
 const values=[];
 sheet.eachRow(row=>row.eachCell(cell=>values.push(cell.value)));
 assert.equal(values.filter(value=>value===active.orderNumber).length,1);
 assert.ok(!values.includes(cancelled.orderNumber));
 assert.equal(values.filter(value=>value===1234.56).length,1);
 assert.equal(JSON.stringify([cancelled,active]),before);
});
