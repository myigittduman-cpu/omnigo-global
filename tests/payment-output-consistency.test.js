import test from 'node:test';
import assert from 'node:assert/strict';
import {upcomingPaymentModel,upcomingPaymentDocumentModel,upcomingMarketplaceEntries} from '../public/upcoming-payment-model.js';
import {upcomingPaymentExportTable,createUpcomingPaymentWorkbookFile} from '../public/upcoming-payment-workbook.js';
import {reportTables} from '../public/report-export-data.js';
import {createWorkbook} from '../public/report-xlsx.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';
import {receivedExportColors} from '../public/payment-export-style.js';

const entry=(id,channel,date,amountCents,paid=false)=>({id,channel,date,amountCents,source:paid?'received':'marketplace',verification:'confirmed',...(paid?{paymentApproval:{at:'2026-11-28T09:00:00Z'}}:{})});
const source={today:'2026-11-28',periodItems:[entry('a','n11','2026-11-29',10000),entry('b','Pazarama','2026-11-30',20000,true),entry('c','n11','2026-12-20',30000)]};
const company={id:2,name:'Örnek mağaza',hasLogo:false};
async function sheet(blob){const book=new Workbook();await book.xlsx.load(await blob.arrayBuffer());return book.getWorksheet(1);}

test('Hakediş Excel ve PDF aynı pazaryeri ve dönem seçimini kullanır; boş pazaryerleri belgeye girmez',async()=>{
 const before=JSON.stringify(source);
 for(const [excluded,months] of [[new Set(['n11']),[]],[new Set(),['2026-12']],[new Set(['Pazarama']),['2026-11','2026-12']]]){
  const model=upcomingPaymentModel(source,'all',['Koçtaş'],'',excluded,months);
  const pdf=upcomingPaymentDocumentModel(model),table=upcomingPaymentExportTable(model);
  assert.deepEqual(table.rows.map(row=>row.slice(0,3)),pdf.rows.flatMap(market=>upcomingMarketplaceEntries(market).map(item=>[market.name,item.date,item.amountCents/100])));
  assert(!table.rows.some(row=>excluded.has(row[0])||row[0]==='Koçtaş'));
  const file=await createUpcomingPaymentWorkbookFile(model,company,{logo:null});
  const exported=await sheet(file.blob);assert.equal(exported.rowCount,7+table.rows.length);assert.match(file.filename,/\.xlsx$/);
 }
 assert.equal(JSON.stringify(source),before);
 await assert.rejects(createUpcomingPaymentWorkbookFile(upcomingPaymentModel(source,'all',[],'',new Set(['n11','Pazarama'])),company,{logo:null}),/hakediş kaydı bulunmuyor/);
});

test('Hakediş Excel onaylı tutarı sayısal tutar; yalnız onaylı satırı yeşil ve tikli gösterir',async()=>{
 const table=upcomingPaymentExportTable(upcomingPaymentModel(source));
 assert.deepEqual(table.receivedRows,[1]);assert.equal(table.rows[1][2],200);assert.match(table.rows[1][3],/✓/);assert.doesNotMatch(table.rows[0][3],/✓/);
 const exported=await sheet((await createUpcomingPaymentWorkbookFile(upcomingPaymentModel(source),company,{logo:null})).blob);
 assert.equal(exported.getCell('C9').value,200);assert.match(exported.getCell('D9').value,/✓/);
 assert.equal(exported.getCell('C9').fill.fgColor.argb,receivedExportColors.fillArgb);
 assert.notEqual(exported.getCell('C8').fill.fgColor.argb,receivedExportColors.fillArgb);
});

test('Analiz raporunda yalnız banka onayı tamamlanan ödeme yeşil ve tiklidir; tutar değişmez',async()=>{
 const pending={channel:'n11',expectedDate:'2026-11-28',expectedCents:25000,status:'paid',paidDate:'2026-11-28',actualCents:24000,confirmation:'Örnek banka'};
 const report={company,from:'2026-11-01',to:'2026-11-30',previousFrom:'2026-10-01',previousTo:'2026-10-31',generatedAt:'2026-11-28T09:00:00Z',totals:{orders:1},previousTotals:null,series:[],products:[],notes:[],payments:{items:[pending,{...pending,paymentApproval:{at:'2026-11-28T09:00:00Z'}},{...pending,integration:'n11',paymentApproval:{at:'2026-11-28T09:00:00Z'}}]},availableChannels:1,selectedChannels:1};
 const before=JSON.stringify(report),table=reportTables(report).find(table=>table.name==='Hakedişler');
 assert.deepEqual(table.receivedRows,[1]);assert.equal(table.rows[1][5],240);assert.match(table.rows[1][3],/✓/);assert.doesNotMatch(table.rows[0][3],/✓/);assert.doesNotMatch(table.rows[2][3],/✓/);
 const exported=await sheet(await createWorkbook(report,[table]));
 assert.equal(exported.getCell('F9').value,240);assert.match(exported.getCell('D9').value,/✓/);
 assert.equal(exported.getCell('F9').fill.fgColor.argb,receivedExportColors.fillArgb);
 assert.notEqual(exported.getCell('F8').fill.fgColor.argb,receivedExportColors.fillArgb);
 assert.equal(JSON.stringify(report),before);
});
