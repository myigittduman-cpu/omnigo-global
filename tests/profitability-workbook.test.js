import test from 'node:test';import assert from 'node:assert/strict';
import {profitabilityDocumentRows,profitabilityDocumentTable} from '../public/profitability-document.js';
import {profitabilityWorkbookTable,createProfitabilityWorkbook} from '../public/profitability-workbook.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';

const company={id:2,name:'Örnek mağaza'},filters={from:'2026-11-01',to:'2026-11-30',channel:'all'};
const item=index=>({orderNumber:'00012345'+index,orderedAt:'2026-11-28T09:10:00Z',channel:'n11',status:'financials_pending',salesCents:1799901,purchaseCents:1500000,afterDeductionsCents:1600000,commissionCents:215988,commissionRateBasisPoints:1200,commissionSource:'marketplace_rate',serviceCents:1308,statementShippingCents:3000,orderPricing:{buyerPaidCents:1799901},products:[{sku:'001234',title:'Örnek ürün',quantity:2,unitPurchaseCents:750000}]});

test('Kârlılık Excel ve PDF aynı 31 kaydı alır; açık sayfa sınırı yoktur',async()=>{
 const all=Array.from({length:31},(_,index)=>item(index)),before=JSON.stringify(all),pages=[];
 const rows=await profitabilityDocumentRows({company,filters,current:()=>true,read:async url=>{
  const params=new URL(url,'https://fixture').searchParams,page=Number(params.get('page'));pages.push(page);assert.equal(params.get('company'),'2');
  return {...filters,page,pageSize:10,total:all.length,rows:all.slice((page-1)*10,page*10)};
 }});
 const table=profitabilityWorkbookTable(rows,'all'),pdf=profitabilityDocumentTable(rows,'all');
 assert.equal(table.rows.length,31);assert.equal(pdf.rows.length,31);assert.deepEqual(pages,[1,2,3,4,1]);
 const report={company,...filters,generatedAt:'2026-11-28T09:00:00Z'},book=new Workbook();await book.xlsx.load(await(await createProfitabilityWorkbook(report,rows,'all')).arrayBuffer());const sheet=book.getWorksheet(1);
 assert.equal(sheet.rowCount,38);assert.equal(sheet.getCell('A8').value,'000123450');assert(sheet.getCell('B8').value instanceof Date);
 assert.equal(sheet.getCell('B8').value.toISOString(),'2026-11-28T12:10:00.000Z');assert.equal(sheet.getCell('E8').value,2);
 assert.equal(sheet.getCell('F8').value,17999.01);assert.equal(sheet.getCell('H8').value,2159.88);assert.equal(sheet.getCell('I8').value,.12);
 assert.equal(sheet.getCell('P8').value,15000);assert.equal(sheet.getCell('R8').value,16000);assert.equal(sheet.getCell('I8').numFmt,'0.00%');
 assert.equal(sheet.getCell('K8').value,13.08);assert.equal(sheet.getCell('O8').value,30);assert.equal(sheet.getCell('J8').value,null);
 assert.match(sheet.getCell('S8').value,/kargo kesintisi/);assert.equal(JSON.stringify(all),before);
});

test('Bilinmeyen tutarlar sıfır yapılmaz; bildirilen ve eski ekstre tutarı açıklaması korunur',()=>{
 const row={...item(1),afterDeductionsCents:undefined,reportedPayoutCents:0,purchaseCents:null,commissionCents:null,commissionRateBasisPoints:null,serviceCents:undefined,status:'statement_stale'};
 const table=profitabilityWorkbookTable([row],'n11');assert.equal(table.rows[0][15],null);assert.equal(table.rows[0][16],null);assert.equal(table.rows[0][17],0);assert.match(table.rows[0][18],/Pazaryerinin bildirdiği hakediş/);
});
