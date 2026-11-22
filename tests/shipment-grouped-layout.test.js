import test from 'node:test';
import assert from 'node:assert/strict';
import {shipmentTable,shipmentRow,shipmentColumns,shipmentDisplayColumns} from '../public/shipments-view.js';
import {sameShipmentAddress} from '../public/shipment-grouped-view.js';
import {shipmentCopyValue} from '../public/shipment-copy.js';
import {setLanguage} from '../public/i18n.js';

const row={id:61,version:4,provider:'n11',orderedAt:'2026-10-30T14:32:00+03:00',customerName:'Örnek Müşteri',recipientName:'Örnek Alıcı',phone:'0500 000 00 01',recipientEmail:'ornek@example.invalid',productName:'Örnek <Ürün>\nUzun ürün açıklaması',deliveryAddress:'Örnek Mahallesi, Açık Cadde No: 12',district:'Altındağ',province:'Ankara',invoiceName:'Örnek Ticaret',invoiceAddress:'Sanayi Mahallesi No: 6',customerType:'Kurumsal',taxNumber:'1234567890',invoiceCents:3061122,quantity:2,rowStatus:'shipped'};
const model=(items=[row])=>({month:'2026-10',provider:'all',city:'all',filters:{},draftChanges:[],selectedIds:new Set(),data:{items,total:items.length,totalRecords:items.length,page:0,pageSize:50}});

test('gruplanan tablo kaynak kayıtları, alanları ve ayrı kopyalama değerlerini korur',()=>{
 const before=structuredClone(row),html=shipmentTable(model());
 assert.equal((html.match(/<th scope="col"/g)||[]).length,7);
 assert.equal((shipmentRow(row).match(/<td /g)||[]).length,7);
 assert.equal(shipmentColumns.length,15);
 for(const key of ['orderedAt','customerName','recipientName','phone','recipientEmail','productName','deliveryAddress','province','district','invoiceName','invoiceAddress','customerType','invoiceCents'])assert.ok(html.includes(`data-shipment-copy="${key}"`),key);
 assert.match(html,/Örnek &lt;Ürün&gt;/);assert.doesNotMatch(html,/<Ürün>/);
 assert.equal(shipmentCopyValue(row,'invoiceAddress'),row.invoiceAddress);
 assert.equal(shipmentCopyValue(row,'phone'),row.phone);
 assert.match(shipmentCopyValue(row,'invoiceCents'),/30\.611,22/);
 assert.deepEqual(row,before);
});
test('aynı adres kısa gösterilir, farklı kapı numarası hiçbir zaman aynı sayılmaz',()=>{
 const same={...row,invoiceAddress:'  ÖRNEK Mahallesi,\n Açık Cadde No: 12  '};
 assert.equal(sameShipmentAddress(same),true);
 for(const layout of ['invoice','grouped','classic']){
  const render=record=>shipmentRow(record,false,false,false,false,false,layout);
  const cells=render(same).match(/<td\b[\s\S]*?<\/td>/g);
  const delivery=cells.find(cell=>cell.includes('data-shipment-copy="deliveryAddress"'));
  const billing=cells.find(cell=>cell.includes(layout==='invoice'?'data-shipment-copy="billingAddress"':'data-shipment-copy="invoiceAddress"'));
  assert.match(delivery,/Fatura adresi ile aynı/);
  assert.match(delivery,/Fatura adresi ile aynı[\s\S]+İl \/ İlçe[\s\S]+Ankara[\s\S]+Altındağ/);
  assert.match(billing,/ÖRNEK Mahallesi,/);
  assert.ok(!render(same).includes(row.deliveryAddress));
  for(const invoiceAddress of ['',row.deliveryAddress+'0']){
   const different=render({...same,invoiceAddress});
   assert.doesNotMatch(different,/Fatura adresi ile aynı/);
   assert.ok(different.includes(row.deliveryAddress));
  }
 }
 assert.equal(shipmentCopyValue(same,'invoiceAddress'),same.invoiceAddress);
 assert.equal(shipmentCopyValue(same,'deliveryAddress'),row.deliveryAddress);
 assert.equal(sameShipmentAddress({...same,invoiceAddress:row.deliveryAddress+'0'}),false);
 assert.equal(sameShipmentAddress({...row,deliveryAddress:'',invoiceAddress:''}),false);
 assert.equal(sameShipmentAddress({...row,deliveryAddress:'—',invoiceAddress:'—'}),false);
 assert.match(shipmentRow({...row,invoiceAddress:'Başka Cadde No: 120'}),/Başka Cadde No: 120/);
});
test('aynı faturada ayrı teslimat adresleri görünür ve her biri kendi değeriyle kopyalanır',()=>{
 const primary={...row,invoiceAddress:row.deliveryAddress+'\nVergi dairesi: Örnek'};
 const other={...primary,id:62,deliveryAddress:row.deliveryAddress+' Daire 4'};
 const html=shipmentRow(primary,false,false,false,false,false,'invoice',[primary,{...primary,id:63},other]);
 const address=html.match(/<td class="shipment-cell-billingAddress">.*?<\/td>/s)[0];
 assert.equal((address.match(/data-shipment-copy="deliveryAddress"/g)||[]).length,2);
 assert.equal((address.match(/Fatura adresi ile aynı/g)||[]).length,1);
 assert.ok(address.includes(other.deliveryAddress));
 assert.ok(address.includes('data-shipment-copy-value="'+other.deliveryAddress+'"'));
 assert.equal(shipmentCopyValue(primary,'billingAddress'),row.deliveryAddress);
});
test('aynı sokak metni farklı illerde teslim ediliyorsa her adresin altına kendi il ve ilçesi yazılır',()=>{
 const second={...row,id:62,province:'Kayseri'};
 const grouped={...model([row,second]),data:{...model([row,second]).data,invoiceGroups:[[row.id,second.id]]}};
 const html=shipmentTable(grouped,'invoice');
 const address=html.match(/<td class="shipment-cell-billingAddress">.*?<\/td>/s)[0];
 assert.match(address,/Sanayi Mahallesi No: 6/);
 assert.ok(address.includes(row.deliveryAddress));
 assert.match(address,/data-shipment-copy="billingAddress"/);
 assert.match(address,/data-shipment-copy="deliveryAddress"/);
 assert.equal((address.match(/İl \/ İlçe/g)||[]).length,2);
 assert.match(address,/İl \/ İlçe[\s\S]+Ankara[\s\S]+Altındağ[\s\S]+İl \/ İlçe[\s\S]+Kayseri/);
 assert.equal(shipmentCopyValue(row,'billingAddress'),row.invoiceAddress);
 assert.equal(shipmentCopyValue(row,'deliveryAddress'),row.deliveryAddress);
 const missing=shipmentRow({...row,province:'',district:''},false,false,false,false,false,'invoice');
 assert.match(missing,/İl \/ İlçe<\/small><div[^>]*><span>—<\/span> \/ <span>—<\/span>/);
});
test('eski görünüme dönüş 15 sütunu ve editör genişliğini geri getirir',()=>{
 const before=structuredClone(row),classic=shipmentTable(model(),'classic');
 assert.equal(shipmentDisplayColumns('classic').length,15);
 assert.equal((classic.match(/<th scope="col"/g)||[]).length,15);
 assert.equal((shipmentRow(row,false,false,false,false,false,'classic').match(/<td /g)||[]).length,15);
 assert.doesNotMatch(classic,/shipment-layout-grouped/);
 const editing={...model(),editor:{id:row.id,values:{...row},saving:false}};
 assert.match(shipmentTable(editing),/colspan="7"/);
 assert.match(shipmentTable(editing,'classic'),/colspan="15"/);
 assert.match(shipmentTable(model([])),/colspan="7"/);
 assert.deepEqual(row,before);
});
test('grup etiketleri çevrilirken kayıt metinleri ve sütun anahtarları değişmez',()=>{
 const keys=shipmentDisplayColumns().map(([key])=>key);
 try{
  setLanguage('en',{persist:false});assert.equal(shipmentDisplayColumns()[2][1],'Customer / Contact');
  assert.match(shipmentRow(row),/Örnek Müşteri/);
  setLanguage('de',{persist:false});assert.equal(shipmentDisplayColumns()[6][1],'Betrag / Anzahl');
  assert.deepEqual(shipmentDisplayColumns().map(([key])=>key),keys);
 }finally{setLanguage('tr',{persist:false});}
});
