import test from 'node:test';
import assert from 'node:assert/strict';
import {createWorkbook} from '../public/report-xlsx.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';
import {receivedExportColors} from '../public/payment-export-style.js';

test('Hakediş Excelinde yalnız onaylanmış satır yeşildir; tik, tutar ve satır sırası korunur',async()=>{
 const report={company:{name:'Örnek Mağaza'},from:'2026-11-01',to:'2026-11-30',previousFrom:'2026-10-01',previousTo:'2026-10-31',generatedAt:'2026-11-28T10:00:00Z'};
 const table={name:'Hakedişlerim',head:['Pazaryeri','Ödeme tarihi','Tutar'],rows:[['n11','28.11.2026','₺82.911,86 ✓'],['n11','29.11.2026','₺43.488,32']],money:[],receivedRows:[0]};
 const before=JSON.stringify({report,table});
 const file=await createWorkbook(report,[table]);const workbook=new Workbook();await workbook.xlsx.load(await file.arrayBuffer());
 const sheet=workbook.getWorksheet(1);
 assert.equal(sheet.getCell('C8').value,'₺82.911,86 ✓');assert.equal(sheet.getCell('C9').value,'₺43.488,32');
 for(const column of ['A','B','C']){
  assert.equal(sheet.getCell(column+'8').fill.fgColor.argb,receivedExportColors.fillArgb);
  assert.equal(sheet.getCell(column+'8').font.color.argb,receivedExportColors.textArgb);
  assert.notEqual(sheet.getCell(column+'9').fill.fgColor.argb,receivedExportColors.fillArgb);
 }
 assert.equal(JSON.stringify({report,table}),before);
});
