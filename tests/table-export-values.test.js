import test from 'node:test';import assert from 'node:assert/strict';
import {exportColumnType,numericExportCell} from '../public/table-export-values.js';
import {createWorkbook} from '../public/report-xlsx.js';import {Workbook} from '../public/vendor/report-xlsx-070.js';

test('Excel tutar/adet sütunlarını sayıya çevirir; kimlik, telefon ve stok kodunu metin bırakır',()=>{
 for(const [label,text,value] of [['Fatura tutarı','₺17.999,01',17999.01],['Adet','1.234',1234],['Alış fiyatı','₺0,00',0],['Tutar','-₺1.234,50',-1234.5]])
  assert.equal(numericExportCell(text,exportColumnType(label),'tr-TR').value,value);
 for(const label of ['Sipariş no','Telefon','SKU','Vergi numarası','Barkod','Ürün kodu'])assert.equal(numericExportCell('00012345',exportColumnType(label)).value,'00012345');
 for(const text of ['—','Aktarılmadı','Ekstre bekleniyor','', '=1+1'])assert.equal(numericExportCell(text,'money').value,text);
 assert.equal(numericExportCell('1,5','integer','tr-TR').value,'1,5');
 assert.equal(numericExportCell('TRY 1,234.56','money','en-US').value,1234.56);
 assert.equal(numericExportCell('1.234,56 ₺','money','de-DE').value,1234.56);
 assert.deepEqual(numericExportCell('₺100,25\nİptal sonrası tutar\nİade: ₺5,00','money','tr-TR'),{value:100.25,note:'İptal sonrası tutar\nİade: ₺5,00'});
});

test('Excel sayı biçimi, açıklama notu ve baştaki sıfırlar kaydedilip yeniden açılınca korunur',async()=>{
 const report={company:{name:'Örnek mağaza'},from:'2026-11-01',to:'2026-11-30',previousFrom:'—',previousTo:'—',generatedAt:'2026-11-28T09:00:00Z'};
 const table={name:'Siparişler',head:['Sipariş no','Adet','Fatura tutarı'],rows:[['00012345',2,17999.01],['00012346',0,0]],money:[2],integers:[1],cellNotes:[['','','İptal sonrası tutar']]};
 const book=new Workbook();await book.xlsx.load(await(await createWorkbook(report,[table])).arrayBuffer());const sheet=book.getWorksheet(1);
 assert.equal(sheet.getCell('A8').value,'00012345');assert.equal(sheet.getCell('B8').value,2);assert.equal(sheet.getCell('C8').value,17999.01);
 assert.equal(sheet.getCell('B8').numFmt,'#,##0');assert.equal(sheet.getCell('B9').value,0);assert.equal(sheet.getCell('C9').value,0);
 assert.match(JSON.stringify(sheet.getCell('C8').note),/İptal sonrası tutar/);
});
