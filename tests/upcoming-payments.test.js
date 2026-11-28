import assert from 'node:assert/strict';
import test from 'node:test';
import {currentPaymentForecast,upcomingPaymentDocumentModel,upcomingPaymentExpectedCents,upcomingPaymentFilename,upcomingPaymentModel,upcomingPaymentTableRows,upcomingPaymentTotals} from '../public/upcoming-payment-model.js';
import {overviewPaymentForecast} from '../public/overview-payments.js';
import {stats} from '../public/views.js';

test('Hakediş kartları yalnız ekranda gösterilen dönemin tutarlarını toplar',()=>{
 const first={channel:'Hepsiburada',date:'2026-11-16',amountCents:40000,verification:'estimated'};
 const confirmed={channel:'n11',date:'2026-11-18',amountCents:50000,verification:'confirmed'};
 const next={...first,date:'2026-12-08',amountCents:30000};
 const report={channel:'all',marketplaces:['Hepsiburada','n11'],upcoming:{today:'2026-11-15',periodItems:[first,confirmed,next]}};
 const active=currentPaymentForecast(report);
 assert.deepEqual(upcomingPaymentTotals(active),{estimatedCents:40000,confirmedCents:50000});
 assert.equal(upcomingPaymentExpectedCents(active),90000);
 assert.deepEqual(upcomingPaymentTotals(currentPaymentForecast(report,next.date)),{estimatedCents:30000,confirmedCents:0});
 assert.deepEqual(upcomingPaymentTotals(currentPaymentForecast(report,'',['2026-11','2026-12'])),{estimatedCents:70000,confirmedCents:50000});
 assert.deepEqual(upcomingPaymentTotals(currentPaymentForecast({...report,channel:'n11'})),{estimatedCents:0,confirmedCents:50000});
});

test('Genel Bakış hakediş kartları açık takvim sayfası ve satış filtresi yerine bu aya aittir',()=>{
 const entry=(date,amountCents,source='marketplace')=>({channel:'n11',date,amountCents,source,verification:'estimated'});
 const upcoming={today:'2026-11-05',paidThisMonthCents:33000,periodItems:[
  entry('2026-11-02',80000),entry('2026-11-06',12300),entry('2026-11-30',23400,'manual'),entry('2026-12-01',90000),
 ]};
 const data={channels:[{name:'n11'}],sales:[],payments:[],summary:{paidCents:27000},upcoming};
 const forecast=overviewPaymentForecast(data);
 const rendered=stats(data,{overview:true,showDetails:false,periodMetrics:{},forecast});
 assert.match(rendered,/Bu Ay Beklenen Hakediş<\/span><strong>₺357,00<\/strong>/);
 assert.match(rendered,/Bu Ay Hesaba Geçen<\/span><strong>₺330,00<\/strong>/);
 assert.equal(upcomingPaymentExpectedCents(forecast),92300);
 const noForecast=stats(data,{overview:true,showDetails:false,periodMetrics:{}});
 assert.match(noForecast,/Bu Ay Beklenen Hakediş<\/span><strong>—<\/strong>/);
});
import {upcomingMonthOptions,upcomingPayments} from '../public/upcoming-payments.js';

const n11={id:1,channel:'n11',date:'2026-11-30',amountCents:12345600};
const pazarama={id:2,channel:'Pazarama',date:'2026-12-04',amountCents:100200,verification:'estimated',source:'marketplace_panel',observedDate:'2026-11-28'};
const overview={
 items:[pazarama,n11],
 remainingCents:15000000,confirmedCents:14000000,estimatedCents:1000000,paidThisMonthCents:11335500,
 today:'2026-11-29',channelsWithoutData:['Koçtaş','<örnek>'],
};

test('Manuel ödeme onayı Düzenle’nin solundadır; müşteri göremez ve onaylı ödeme beklenen tutara eklenmez',()=>{
 const pending={...n11,adjustmentRevision:'revision',source:'marketplace'};
 const source={...overview,periodItems:[pending]};
 const markup=upcomingPayments(source);
 assert(markup.indexOf('data-upcoming-approve')<markup.indexOf('data-upcoming-record'));
 assert.match(markup,/data-upcoming-approve="1">Manuel onayla/);
 assert.doesNotMatch(upcomingPayments(source,'all',[],null,'',{canManage:false}),/data-upcoming-approve/);
 const received={...pending,id:'receipt:10',adjustmentRevision:undefined,source:'received',paymentApproval:{at:'2026-11-28T10:00:00Z'}};
 const paidMarkup=upcomingPayments({...overview,periodItems:[received]});
 assert.match(paidMarkup,/class="payment-approved-check"/);
 assert.match(paidMarkup,/data-payment-received/);
 assert.doesNotMatch(paidMarkup,/data-upcoming-approve/);
 assert.equal(upcomingPaymentExpectedCents(upcomingPaymentModel({...overview,periodItems:[received,pending]})),pending.amountCents);
});

test('Pazaryerleri tek tabloda gruplanır ve yalnız gerçek ödeme tarihleri gösterilir',()=>{
 const rendered=upcomingPayments(overview,'all',['n11','Pazarama','Koçtaş']);
 assert.equal([...rendered.matchAll(/<table\b/g)].length,1);
 assert.match(rendered,/<th scope="rowgroup" class="upcoming-payments-market"/);
 assert.match(rendered,/<time datetime="2026-11-30">30\.11\.2026<\/time>/);
 assert(rendered.indexOf('2026-11-30')<rendered.indexOf('2026-12-04'));
 assert.doesNotMatch(rendered,/\d\. ödeme/);
 assert.match(rendered,/123\.456,00/);
 assert.match(rendered,/Koçtaş[\s\S]*?Yaklaşan ödeme kaydı yok/);
 assert.match(rendered,/&lt;örnek&gt;/);
 assert.doesNotMatch(rendered,/Pazaryeri tahmini|Panel kontrolü/);
 assert.match(rendered,/Beklenen tahmini hakediş[\s\S]*?>₺124\.458,00</);
 assert.match(rendered,/data-upcoming-download[\s\S]*data-upcoming-print[\s\S]*data-upcoming-share/);
 for(const action of ['download','print','share']){
  assert.doesNotMatch(rendered,new RegExp('data-upcoming-'+action+' disabled'));
  assert.match(upcomingPayments({...overview,items:[]}),new RegExp('data-upcoming-'+action+' disabled'));
 }
 assert.match(upcomingPayments(),/Hakedişler hazırlanıyor/);
});

test('Aynı pazaryerinin aynı tarihli ödemeleri ayrı kalır ve her satır toplama girer',()=>{
 const second={...n11,id:3,amountCents:200100};
 const sharedDate={...pazarama,id:4,date:n11.date};
 const item={...overview,items:[...overview.items,second,sharedDate]};
 const model=upcomingPaymentModel(item,'all',['Pazarama','n11','Hepsiburada','Teknosa']);
 assert.deepEqual(model.dates,['2026-11-30','2026-12-04']);
 assert.deepEqual(model.rows.find(row=>row.name==='n11').payments.get(n11.date),[n11,second]);
 assert.deepEqual(model.rows.find(row=>row.name==='Pazarama').payments.get(n11.date),[sharedDate]);
 assert.equal(model.rows.find(row=>row.name==='Hepsiburada').payments.size,0);
 assert.equal(model.rows.some(row=>row.name==='Teknosa'),false);
 assert.equal(upcomingPaymentExpectedCents(model),12746100);
 const table=upcomingPayments(item,'n11');
 assert.equal([...table.matchAll(/upcoming-payments-date"><time datetime="2026-11-30"/g)].length,2);
 assert.equal([...table.matchAll(/class="upcoming-payment-market-arrow" aria-hidden="true"><span/g)].length,2);
 assert.match(table,/rowspan="2"/);
 assert.match(table,/123\.456,00[\s\S]*2\.001,00/);
});

test('Pazaryeri oku her ödeme tarihinde aynı satırda görünür',()=>{
 const first={...n11,date:'2026-11-26'};
 const second={...n11,date:'2026-11-27'};
 const table=upcomingPayments({...overview,items:[first,second,n11]},'n11');
 const entries=[...table.matchAll(/<tr class="upcoming-payment-entry">([\s\S]*?)<\/tr>/g)].map(match=>match[1]);
 assert.equal(entries.length,3);
 assert.equal(entries.filter(row=>row.includes('scope="rowgroup"')).length,1);
 for(const [index,row] of entries.entries()){
  assert.match(row,new RegExp('upcoming-payment-market-arrow[^>]*><span[\\s\\S]*?datetime="'+[first,second,n11][index].date+'"'));
 }
});

test('Pazaryeri filtresi tarihleri ve toplamı seçilen pazaryerinin ödemelerinden hesaplar',()=>{
 const model=upcomingPaymentModel(overview,'n11',['Pazarama','n11']);
 assert.deepEqual(model.dates,[n11.date]);
 assert.deepEqual(model.rows.map(row=>row.name),['n11']);
 assert.equal(upcomingPaymentExpectedCents(model),n11.amountCents);
 assert.doesNotMatch(upcomingPayments(overview,'n11'),/Pazarama|2026-12-04/);
});

test('Hakediş başlığı ve görseli seçili mağazayı kullanır',()=>{
 const company={id:2,name:'VİZYONA',hasLogo:true,brandingVersion:7};
 const rendered=upcomingPayments(overview,'all',[],company);
 assert.match(rendered,/>VİZYONA Pazaryeri Ödemeleri</);
 assert.match(rendered,/class="upcoming-payments-company-logo" data-company-logo="2"/);
 assert.match(rendered,/\/api\/management\/logo\?fit=payment&company=2&v=7/);
 assert.doesNotMatch(upcomingPayments(overview),/upcoming-payments-company-logo/);
 const otherCompany=upcomingPayments(overview,'all',[],{id:3,name:'Örnek Mağaza',hasLogo:false});
 assert.match(otherCompany,/>Örnek Mağaza Pazaryeri Ödemeleri</);
 assert.doesNotMatch(otherCompany,/upcoming-payments-company-logo/);
});

test('Hakediş görüntüyen kullanıcı PDF alabilir, yönetim işlemleri ayrı kalır',()=>{
 const manual={...n11,id:61,source:'manual'};
 const automatic={...pazarama,id:'pazarama:row',adjustmentRevision:'revision'};
 const source={...overview,periodItems:[manual,automatic],manualRecords:[{id:61}],manualChannels:['n11','Pazarama','Koçtaş'],manualForecastChannels:['Koçtaş']};
 const client=upcomingPayments(source,'all',[],null,'',{canManage:false,canExport:true});
 assert.match(client,/data-upcoming-download/);
 assert.match(client,/data-upcoming-share/);
 assert.match(client,/data-upcoming-print/);
 assert.doesNotMatch(client,/data-upcoming-manual|data-upcoming-review|data-upcoming-record/);
 assert.equal([...client.matchAll(/class="upcoming-market-manual"/g)].length,1);
 assert.match(client,/alt="Koçtaş"[^>]*><\/span><\/span>\s*<small class="upcoming-market-manual"[^>]*>Manuel<\/small>/);
 const admin=upcomingPayments(source,'all',[],null,'',{canManage:true,editing:true});
 assert.match(admin,/alt="Koçtaş"[^>]*><\/span><\/span>\s*<small class="upcoming-market-manual"/);
 assert.match(admin,/data-upcoming-market-selection/);
 assert.match(admin,/data-upcoming-record="61"/);
 assert.match(admin,/data-upcoming-record="pazarama:row" data-upcoming-remove/);
 assert.doesNotMatch(admin,/data-manual-payout-note/);
 assert.match(admin,/title="Elle girildi"/);
 const restricted=upcomingPayments(source,'all',[],null,'',{canManage:false,canExport:false});
 assert.doesNotMatch(restricted,/data-upcoming-download|data-upcoming-share|data-upcoming-print/);
});

test('Birleşik çıktı yalnız seçilen ayların ödemelerini içerir ve normal sayfa konumunu korur',()=>{
 const history={...n11,id:3,date:'2026-10-20',amountCents:1000};
 const historyOther={...history,id:4,amountCents:500};
 const future={...n11,id:5,date:'2026-12-22',amountCents:1500};
 const excludedFuture={...future,id:6,channel:'Koçtaş',amountCents:100000};
 const archive={...overview,periodItems:[pazarama,n11,history,future,historyOther,excludedFuture],today:'2026-11-30'};
 const base=upcomingPaymentModel(archive,'all',[],'2026-12-22',new Set(['Koçtaş']));
 const combined=upcomingPaymentModel(archive,'all',[],'2026-12-22',new Set(['Koçtaş']),
  ['2026-12','2026-10','2026-10','2026-01']);
 assert.deepEqual(combined.selectedMonths,['2026-10','2026-12']);
 assert.equal(combined.page,base.page);
 assert.deepEqual(combined.periods,base.periods);
 assert.deepEqual(combined.dates,['2026-10-20','2026-12-04','2026-12-22']);
 assert.deepEqual(combined.rows.find(row=>row.name==='n11').payments.get(history.date),[history,historyOther]);
 assert.equal(upcomingPaymentExpectedCents(combined),103200);
 assert.equal(upcomingPaymentFilename(combined,{name:'VİZYONA'},'2026-11-21T11:00:00Z'),'Vizyona – 21.11.2026 Ödeme Tablosu.pdf');
});

test('Seçilen aylar artık bulunmuyorsa belgeye güncel dönem eklenmez',()=>{
 const empty=upcomingPaymentModel(overview,'all',[],'',new Set(),['2026-01']);
 assert.equal(empty.period,null);
 assert.deepEqual(empty.selectedMonths,[]);
 assert.deepEqual(empty.dates,[]);
 const normal=upcomingPaymentModel(overview);
 assert.equal(normal.period.start,n11.date);
 assert.deepEqual(normal.selectedMonths,[]);
});

test('Boş pazaryerleri ekranda sabit yer tutar, gerçek ödeme yalnız kendi satırının yerini alır',()=>{
 const markets=['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol'];
 const zero={...pazarama,amountCents:0,date:'2027-01-01'};
 const empty={today:'2026-11-30',periodItems:[zero]};
 const initial=upcomingPaymentModel(empty);
 assert.deepEqual(upcomingPaymentTableRows(initial,'all').map(row=>row.name),markets);
 assert.deepEqual(initial.periods,[]);
 const markup=upcomingPayments(empty,'all',['Teknosa','Pazarama']);
 assert.equal((markup.match(/class="upcoming-payment-empty-row"/g)||[]).length,7);
 assert.equal((markup.match(/<strong class="upcoming-payments-amount">[^<]*0,00/g)||[]).length,7);
 assert.doesNotMatch(markup,/Teknosa|upcoming-table-space|2027-01-01/);
 const updated=upcomingPaymentModel({...empty,periodItems:[zero,n11]});
 const table=upcomingPaymentTableRows(updated,'all');
 assert.deepEqual(table.map(row=>row.name),markets);
 assert.equal(table.filter(row=>row.payments.size===0).length,6);
 assert.deepEqual(table[1].payments.get(n11.date),[n11]);
 assert.deepEqual(upcomingPaymentDocumentModel(updated).rows.map(row=>row.name),['n11']);
 assert.deepEqual(upcomingPaymentDocumentModel(updated).dates,[n11.date]);
 const zeroMarket={name:'Pazarama',payments:new Map([[zero.date,[zero]]])};
 const doc=upcomingPaymentDocumentModel({rows:[zeroMarket,{...zeroMarket,name:'Teknosa'},...updated.rows]});
 assert.deepEqual(doc.rows.map(row=>row.name),['n11']);
 assert.equal(upcomingPaymentExpectedCents(doc),n11.amountCents);
});

test('Onay kartı tutarın yer aldığı 15 günlük sayfada ve pazaryerinde görünür',()=>{
 const row=(date,channel)=>({...n11,date,channel});
 const current=row('2026-11-20','n11'),last=row('2026-12-04','n11'),future=row('2026-12-05','Pazarama');
 const change=(item,key)=>({key,channel:item.channel,date:item.date,beforeCents:12000,proposedCents:18000});
 const items=[current,last,future];
 const source={today:'2026-11-22',periodItems:items,pendingChanges:[
  change(current,'current'),change(last,'last-day'),change(future,'future'),
 ]};
 const first=upcomingPayments(source);
 assert.match(first,/data-upcoming-review="current"/);
 assert.match(first,/data-upcoming-review="last-day"/);
 assert.doesNotMatch(first,/data-upcoming-review="future"/);
 const second=upcomingPayments(source,'all',[],null,future.date);
 assert.match(second,/data-upcoming-review="future"/);
 assert.doesNotMatch(second,/data-upcoming-review="(?:current|last-day)"/);
 assert.doesNotMatch(upcomingPayments(source,'n11',[],null,future.date),/data-upcoming-review=/);
 const merged=upcomingPayments(source,'all',[],null,'',{selectedMonths:['2026-12']});
 assert.match(merged,/data-upcoming-review="last-day"/);
 assert.match(merged,/data-upcoming-review="future"/);
 assert.doesNotMatch(merged,/data-upcoming-review="current"/);
});

test('Seçicideki ay ve yıl seçimi 15 günlük dönem sınırını aşan ödemeleri kendi tarihlerinde sayar',()=>{
 const december={...n11,id:3,date:'2026-12-30',amountCents:420};
 const january={...n11,id:4,date:'2027-01-02',amountCents:1000};
 const archive={...overview,periodItems:[pazarama,n11,december,january],today:'2026-12-30'};
 const normal=upcomingPaymentModel(archive);
 assert.deepEqual(normal.dates,['2026-12-30','2027-01-02']);
 assert.deepEqual(normal.months.map(({month,items})=>[month,items.length]),[
  ['2026-11',1],['2026-12',2],['2027-01',1],
 ]);
 const markup=upcomingMonthOptions(normal.months,['2027-01']);
 assert.match(markup,/<legend>2026<\/legend>[\s\S]*Kasım 2026[\s\S]*Aralık 2026[\s\S]*2 kayıt[\s\S]*<legend>2027<\/legend>[\s\S]*Ocak 2027/);
 assert.match(markup,/data-upcoming-month="2027-01" checked/);
 const decemberOnly=upcomingPaymentModel(archive,'all',[],'',new Set(),['2026-12']);
 assert.deepEqual(decemberOnly.dates,['2026-12-04','2026-12-30']);
 assert.deepEqual(decemberOnly.period.items,[pazarama,december]);
 assert.equal(decemberOnly.period.lastPaymentDay,december.date);
 assert.equal(upcomingPaymentExpectedCents(decemberOnly),100620);
 assert.equal(upcomingPaymentFilename(decemberOnly,{name:'VİZYONA'},'2026-11-21T11:00:00Z'),'Vizyona – 21.11.2026 Ödeme Tablosu.pdf');
 const januaryOnly=upcomingPaymentModel(archive,'n11',[],'',new Set(),['2027-01']);
 assert.deepEqual(januaryOnly.months.map(({month,items})=>[month,items.length]),[
  ['2026-11',1],['2026-12',1],['2027-01',1],
 ]);
 assert.deepEqual(januaryOnly.dates,['2027-01-02']);
 assert.equal(upcomingPaymentExpectedCents(januaryOnly),1000);
 const table=upcomingPayments(archive,'all',[],null,'',{selectedMonths:['2027-01']});
 assert.match(table,/upcoming-payment-footer[\s\S]*data-upcoming-period-picker/);
 assert.doesNotMatch(table,/data-upcoming-page="/);
 assert.match(table,/1 seçili ay/);
});

