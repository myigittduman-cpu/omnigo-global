import test from 'node:test';
import assert from 'node:assert/strict';
import {Workbook} from '../public/vendor/report-xlsx-070.js';
import {createShipmentWorkbook,createShipmentPdf,shipmentExportValue} from '../public/shipment-export.js';
import {createWorkbook} from '../public/report-xlsx.js';
import {createDeliveryWorkbook} from '../public/delivery-workbook.js';
import {reportTables} from '../public/report-export-data.js';
import {turkeyWorkbookDate} from '../public/workbook-theme.js';

const shipment={id:1,orderedAt:'2026-10-18T21:30:00Z',recipientName:'Örnek Alıcı',customerName:'Örnek Müşteri',phone:'05000000000',province:'Ankara',district:'Çankaya',productName:'Örnek ürün',quantity:2,invoiceCents:2151660,deliveryAddress:'Örnek Mahallesi No: 1',invoiceName:'Örnek Şirket',invoiceAddress:'Örnek Adres',recipientEmail:'ornek@example.test',customerType:'Kurumsal',taxNumber:'0012345678',taxOffice:'Çankaya',rowStatus:'shipped'};
async function load(blob){const workbook=new Workbook();await workbook.xlsx.load(await blob.arrayBuffer());return workbook;}
test('Sevkiyat PDF ve Excel yalnızca kendi teslimat ilini ve ilçesini kullanır',async()=>{
 const located={...shipment,province:'Kırıkkale',district:'Bahşılı',invoiceAddress:'Fatura Adresi Ankara Çankaya'};
 const partial={...located,province:'',district:'Eldivan',deliveryAddress:''};
 const unknown={...located,province:'',district:''};
 assert.equal(shipmentExportValue(located,'deliveryAddress'),'Örnek Mahallesi No: 1\nİl / İlçe · Kırıkkale / Bahşılı');
 assert.equal(shipmentExportValue(partial,'deliveryAddress'),'İlçe · Eldivan');
 assert.equal(shipmentExportValue(unknown,'deliveryAddress'),unknown.deliveryAddress);
 const data={provider:'n11',city:'all',month:'2026-10',total:4,items:[located,partial,unknown,{...located,cancelled:true,district:'Gizli İlçe'}]};
 const sheet=(await load(await createShipmentWorkbook(data))).getWorksheet(1);
 assert.equal(sheet.rowCount,10);
 assert.deepEqual([7,8,9,10].map(index=>['D','E'].map(col=>sheet.getCell(col+index).text)),[
  ['Teslimat ili','Teslimat ilçesi'],['Kırıkkale','Bahşılı'],['','Eldivan'],['','']]);
});
test('Sevkiyat Excel tüm kayıtları, tutarları, kimlikleri ve Türkiye saatini kayıpsız saklar',async()=>{
 const data={company:{id:1,name:'Vizyona',hasLogo:false},provider:'Hepsiburada',city:'all',month:'2026-10',total:41,query:'Örnek',filters:{rowStatus:'shipped'},items:Array.from({length:41},(_,index)=>({...shipment,id:index+1,recipientName:index===0?'=HYPERLINK("example.test")':'Örnek '+index,invoiceCents:index===1?null:index===2?0:shipment.invoiceCents}))};
 const original=JSON.stringify(data),workbook=await load(await createShipmentWorkbook(data)),sheet=workbook.getWorksheet('Sevkiyatlar');
 assert.equal(sheet.rowCount,48);assert.equal(sheet.getCell('H8').value,21516.6);assert.equal(sheet.getCell('H9').value,null);assert.equal(sheet.getCell('H10').value,0);
 assert.equal(workbook.creator,'Vizyona');assert.equal(sheet.getCell('A2').value,'Vizyona');assert.match(sheet.headerFooter.oddFooter,/Vizyona/);assert.equal(sheet.getImages().length,0);
 assert.equal(sheet.getCell('A8').value.toISOString(),'2026-10-19T00:30:00.000Z');assert.equal(sheet.getCell('C8').value,'05000000000');assert.equal(sheet.getCell('N8').value,'0012345678');
 assert.equal(sheet.getCell('B8').value,'=HYPERLINK("example.test")');assert.equal(sheet.getCell('B8').type,3);
 assert.equal(sheet.columnCount,18);assert.equal(sheet.getCell('D8').value,'Ankara');assert.equal(sheet.getCell('E8').value,'Çankaya');assert.match(sheet.getCell('A4').value,/Örnek/);assert.match(sheet.getCell('A4').value,/41 kayıt/);
 assert.ok(!sheet.views.some(view=>view.state==='frozen'||view.state==='split'));assert.equal(sheet.views[0].showGridLines,false);assert.equal(Object.keys(sheet.tables).length,0);assert.equal(sheet.autoFilter,undefined);assert.doesNotMatch(sheet.getCell('A4').value,/Türkiye saati/);
 for(let row=7;row<=48;row++)for(let column=1;column<=18;column++)for(const side of ['top','bottom','left','right'])assert.ok(sheet.getCell(row,column).border[side]?.style,`${row},${column}: ${side}`);
 assert.equal(sheet.conditionalFormattings.length,0);const added=sheet.addRow(['2026-10-20','Yeni Alıcı']);assert.equal(added.getCell(2).font.name,sheet.getCell('B8').font.name);for(const side of ['top','bottom','left','right'])assert.ok(added.getCell(2).border[side]?.style);assert.equal(JSON.stringify(data),original);
});
test('Sevkiyat Excel durum sütunu içermez; geçersiz tarih sıfır tarihe dönüşmez',async()=>{
 const data={provider:'n11',city:'all',month:'2026-10',total:2,items:[{...shipment,marketplaceCancellation:{cancelledQuantity:1}},{...shipment,cancelled:true,orderedAt:''}]};
 const sheet=(await load(await createShipmentWorkbook(data))).getWorksheet(1);
 assert.equal(sheet.getCell('A9').value,null);assert.ok(!sheet.getRow(7).values.some(value=>/durum/i.test(String(value))));
 assert.equal(turkeyWorkbookDate('2026-10-18 16:40:00').toISOString(),'2026-10-18T16:40:00.000Z');
 assert.equal(turkeyWorkbookDate('geçersiz'),null);
});
test('Raporun beş sayfasındaki değerler, bilinmeyen tutarlar ve tarihsiz hakediş korunur',async()=>{
 const report={company:{name:'Örnek Firma'},from:'2026-10-01',to:'2026-10-18',previousFrom:'2026-09-01',previousTo:'2026-09-18',generatedAt:'2026-10-18T22:00:00Z',availableChannels:1,selectedChannels:2,totals:{turnoverCents:123456,turnoverAverageCents:61728,panelSalesCents:123456,netCents:120000,orders:2,averageCents:60000},previousTotals:null,series:[{channel:'n11',available:true,asOf:'2026-10-18T22:00:00Z',days:[{date:'2026-10-18',netCents:120000,orders:2,panelSalesCents:123456}]}],notes:[],products:[{channel:'n11',sku:'001234',title:'Örnek Ürün',quantity:2,netQuantity:2,grossCents:123456,cancelCents:3456,returnCents:0,netCents:120000}],payments:{items:[],undatedItems:[{channel:'Pazarama',expectedCents:123456,status:'expected',expectedDate:null,actualCents:null,source:'API'}]}};
 const original=JSON.stringify(report),tables=reportTables(report),workbook=await load(await createWorkbook(report,tables));
 assert.equal(workbook.worksheets.length,5);
 for(const table of tables){const sheet=workbook.getWorksheet(table.name);assert.equal(sheet.rowCount,7+table.rows.length);assert.equal(sheet.getCell('A7').value,table.head[0]);assert.equal(sheet.views[0].showGridLines,false);
  for(let row=7;row<=sheet.rowCount;row++)for(let column=1;column<=table.head.length;column++)for(const side of ['top','bottom','left','right'])assert.ok(sheet.getCell(row,column).border[side]?.style);
  if(table.rows.length)assert.ok(sheet.getTable('ReportTable'+sheet.id));
 }
 assert.equal(workbook.getWorksheet('Özet').getCell('B8').value,1234.56);assert.equal(workbook.getWorksheet('Özet').getCell('C8').value,null);assert.equal(workbook.getWorksheet('Özet').getCell('B10').numFmt,'#,##0');
 assert.equal(workbook.getWorksheet('Günlük satışlar').getCell('B8').value.toISOString(),'2026-10-18T00:00:00.000Z');
 assert.equal(workbook.getWorksheet('En çok satanlar').getCell('B8').value,'001234');assert.equal(workbook.getWorksheet('En çok satanlar').getCell('E8').value,2);
 assert.equal(workbook.getWorksheet('Hakedişler').getCell('B8').value,'Tarih belirlenmedi');assert.equal(workbook.getWorksheet('Hakedişler').getCell('F8').value,null);assert.equal(JSON.stringify(report),original);
});
test('Teslimat Excel seçili siparişleri ve ürünleri korur, pazaryerlerini ayrı sayfalara yazar',async()=>{
 const order={provider:'n11',orderNumber:'000123456',recipientName:'Örnek',address:'Örnek Adres',phone:'05000000000',city:'Ankara',createdAt:'2026-10-18T10:00:00+03:00',products:[{sku:'00123',title:'Uzun ürün adı '.repeat(12),quantity:2},{sku:'00999',title:'İkinci ürün',quantity:1}]};
 const snapshot={city:'Ankara',sender:{name:'Örnek Gönderici',phone:'05000000001'},orders:[order,{...order,orderNumber:'000654321'},{...order,provider:'Hepsiburada'}]},original=JSON.stringify(snapshot);
 const workbook=await load(await createDeliveryWorkbook(snapshot,{}));
 assert.equal(workbook.worksheets.length,2);
 const sheet=workbook.getWorksheet('n11'),values=sheet.getSheetValues().flat(2);
 assert.ok(values.includes('000123456'));assert.ok(values.includes('000654321'));assert.ok(values.includes('00123'));assert.ok(values.includes('05000000000'));assert.equal(sheet.getColumn(3).values.filter(v=>v==='İkinci ürün').length,2);
 const productHeaders=[];
 sheet.eachRow(row=>{
  if(row.getCell(1).value==='Ürün Kodu'&&row.getCell(3).value==='Ürün Adı'&&row.getCell(6).value==='Adet')productHeaders.push(row.number);
 });
 assert.equal(productHeaders.length,2);
 for(const header of productHeaders){
  const first=sheet.getRow(header+1),second=sheet.getRow(header+2);
  assert.deepEqual([first.getCell(1).value,first.getCell(3).value,first.getCell(6).value],['00123',order.products[0].title.trim(),2]);
  assert.deepEqual([second.getCell(1).value,second.getCell(3).value,second.getCell(6).value],['00999','İkinci ürün',1]);
  for(const column of [1,3,6])for(const side of ['top','bottom','left','right'])assert.equal(first.getCell(column).border[side]?.style,'thin');
 }
 assert.equal(sheet.views[0].showGridLines,false);assert.equal(JSON.stringify(snapshot),original);
 assert.match(sheet.getCell('A1').value,/Toplam: 2 sipariş · 6 parça ürün/);
 const senderRow=sheet.getColumn(1).values.indexOf('Gönderici Adı');
 assert.ok(senderRow>0);
 for(const column of [1,3])for(const side of ['top','bottom','left','right'])assert.equal(sheet.getCell(senderRow,column).border[side]?.style,'thin');
 await assert.rejects(createDeliveryWorkbook({orders:[]},{}),/sipariş bulunamadı/);
});
test('PDF indirme yolu aynı veriyle üretmeye devam eder',async()=>{
 const blob=await createShipmentPdf({provider:'n11',city:'all',month:'2026-10',total:1,items:[shipment]});
 assert.match((await blob.text()).slice(0,8),/^%PDF/);assert.ok(blob.size>1000);
});
