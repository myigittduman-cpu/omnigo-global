import test from 'node:test';
import assert from 'node:assert/strict';
import {analysisResult,analysisShell,comparisonLabel} from '../public/report-view.js';
import {salesChart} from '../public/report-chart.js';
import {reportTables} from '../public/report-export-data.js';
import {paymentCalendar} from '../public/payment-calendar.js';
import {salesPeriodMetrics} from '../public/sales-period-metrics.js';
import {analysisPeriodRange,analysisMonthKeys,analysisPeriodButtons,futureMonthReport} from '../public/analysis-periods.js';
test('Otomatik sipariş hakedişi elle yönetilen ekranda gösterilmez',()=>{
 const html=paymentCalendar({from:'2026-10-01',to:'2026-10-31',items:[],summary:{estimatedCents:0,confirmedCents:0,receivedCents:0,sentCents:0},earningsItems:[{channel:'idefix',expectedDate:'',expectedCents:12000,status:'pending',paymentStateUnknown:true,excludedFromTotals:true,verification:'confirmed',verificationSource:'idefix resmi sipariş servisi',integration:'idefix'}]});
 assert.doesNotMatch(html,/Hakedişler yalnızca elle girilir/);assert.doesNotMatch(html,/Siparişlerden bildirilen hakediş|idefix|₺120,00/);
});
test('Rapor karşılaştırması sıfır taban ve eksik veriyi ayırır',()=>{
 assert.match(comparisonLabel(100,50),/\+100%/);assert.match(comparisonLabel(100,0),/yüzde hesaplanmaz/);assert.match(comparisonLabel(100,null),/verisi yok/);assert.equal(comparisonLabel(0,0),'Değişim yok');
});
test('Analiz filtresi İstanbul tarihine göre Haziran başından açılır ve elle değiştirilen tarih korunur',({mock})=>{
 mock.timers.enable({apis:['Date'],now:new Date('2026-11-13T21:30:00Z')});
 const workspace=name=>({company:1,month:'2026-11',data:{company:{name},channels:[]}});
 const vizyona=workspace('VİZYONA');
 const html=analysisShell(vizyona);
 assert.match(html,/name="from" value="2026-06-01"/);
 assert.match(html,/name="to" value="2026-11-14"/);
 assert.match(html,/data-analysis-period="history"[\s\S]*data-analysis-period="current"[^>]*>Bu Ay/);
 vizyona.reportFilters.from='2026-07-01';
 assert.match(analysisShell(vizyona),/name="from" value="2026-07-01"/);
 assert.match(analysisShell(workspace('VIZYONA')),/name="from" value="2026-06-01"/);
 assert.match(analysisShell(workspace('Terzioğlu Shop')),/name="from" value="2026-06-01"/);
 mock.timers.setTime(new Date('2028-03-01T00:00:00Z').getTime());
 assert.match(analysisShell(workspace('VİZYONA')),/name="from" value="2026-06-01"/);
});

test('Bu Ay İstanbul tarihine göre ilerler ve tamamlanan ay adlandırılmış listeye geçer',()=>{
 assert.deepEqual(analysisPeriodRange('current','2026-11-15'),{from:'2026-11-01',to:'2026-11-15'});
 assert.deepEqual(analysisMonthKeys('2026-11-15').slice(0,3),['2026-12','2027-01','2027-02']);
 assert.deepEqual(analysisMonthKeys('2026-12-01').slice(0,3),['2026-11','2027-01','2027-02']);
 assert.deepEqual(analysisPeriodRange('2026-12','2026-12-01'),{from:'2026-12-01',to:'2026-12-01'});
 assert.deepEqual(analysisPeriodRange('2026-11','2026-12-01'),{from:'2026-11-01',to:'2026-11-30'});
 assert.deepEqual(analysisPeriodRange('2028-02','2027-12-01'),{from:'2028-02-01',to:'2028-02-29'});
 assert.match(analysisPeriodButtons('2026-12','2026-12-01'),/data-analysis-period="current" aria-pressed="true"/);
});

test('Gelecek ay aynı rapor alanlarını ve sıfır metriklerini gösterir',()=>{
 const report=futureMonthReport({from:'2026-12-01',to:'2026-12-31',channel:'n11'},{name:'Örnek'},[{name:'n11'},{name:'Pazarama'}]);
 const markup=analysisResult(report);
 assert.match(markup,/Bu ay için satış aktarımı bekleniyor/);
 assert.match(markup,/En çok satanlar[\s\S]*Satış grafiği/);
 assert.equal((markup.match(/<strong>₺0,00<\/strong>/g)||[]).length,4);
 assert.deepEqual(reportTables(report)[0].rows.map(row=>row[1]),[0,0,0,0,0,0]);
 assert.deepEqual(report.series.map(series=>series.channel),['n11']);
});
test('Analiz seçili dönemin tutarlarını, Genel Bakış takvim dönemlerini gösterir',()=>{
 const report={from:'2026-07-01',to:'2026-08-31',previousFrom:'2026-04-30',previousTo:'2026-06-30',
  totals:{turnoverCents:122000,cancelledTurnoverCents:10000,orders:4,returnCents:300},
  previousTotals:null,notes:[],products:[],series:[],comparisonAvailable:false};
 const html=analysisResult(report);
 assert.match(html,/1 Temmuz 2026 – 31 Ağustos 2026/);
 assert.match(html,/₺1\.120,00/);
 assert.doesNotMatch(html,/Bu yıl|Son 3 ay|sales-period-metrics/);
 const period={from:report.from,to:report.to,turnoverCents:122000,orders:4};
 const periods=salesPeriodMetrics({month:period,previousMonth:{...period,turnoverCents:100000},year:period,threeMonths:period});
 assert.match(periods,/Bu Ay[\s\S]*\+%22[\s\S]*Geçen Ay[\s\S]*Son 3 Ay/);assert.doesNotMatch(periods,/Bu yıl/);
});
test('Analiz dönem karşılaştırması yerine seçili dönem metriklerini gösterir',()=>{
 const report={from:'2026-07-01',to:'2026-08-31',previousFrom:'2026-04-30',previousTo:'2026-06-30',
  totals:{orders:4},previousTotals:null,products:[],series:[],comparisonAvailable:false,
  notes:['Hepsiburada: seçilen dönemin tamamı henüz aktarılmadı.','Bazı iadeler doğrulanamadı.']};
 const html=analysisResult(report);
 assert.doesNotMatch(html,/değişim yüzdesi gösterilmiyor|seçilen dönemin tamamı henüz aktarılmadı/);
 assert.doesNotMatch(html,/Karşılaştırma verisi yok/);
 assert.match(html,/Bazı iadeler doğrulanamadı\./);
 assert.doesNotMatch(analysisResult({...report,previousTotals:{orders:2},comparisonAvailable:true}),/önceki döneme göre/);
});
test('Grafik bilinmeyen günleri sıfır çizmez ve metinleri kaçırır',()=>{
 const html=salesChart([{channel:'<script>',available:true,days:[{date:'2026-10-01',netCents:100},{date:'2026-10-02',netCents:null},{date:'2026-10-03',netCents:200}]}]);
 assert.ok(!html.includes('<script>'));assert.match(html,/M76 [\d.]+\s+M/);assert.ok(!html.includes('NaN'));
});
test('Teyit bekleyen gün grafikte eksik kalır ve dışa aktarımda belirtilir',()=>{
 const report={company:{name:'Örnek'},from:'2026-06-17',to:'2026-06-18',previousFrom:'2026-06-15',previousTo:'2026-06-16',
  totals:{turnoverCents:100000,orders:2},previousTotals:null,products:[],notes:[],payments:{items:[]},
  series:[{channel:'Hepsiburada',available:true,days:[{date:'2026-06-17',coverageLimited:true,turnoverCents:null,orders:null},
   {date:'2026-06-18',turnoverCents:0,orders:0}]}]};
 const html=analysisResult(report);
 assert.match(html,/Toplamlar doğrulanmış kayıtları içerir\./);
 assert.doesNotMatch(html,/Günlük tutarları tablo olarak göster|17\.06\.2026 · ₺0,00/);
 assert.match(html,/18\.06\.2026 · ₺0,00/);
 const tables=reportTables(report);
 assert.deepEqual(tables[1].rows[0],['Hepsiburada','2026-06-17',null,null,'Teyit bekliyor']);
 assert.deepEqual(tables[1].rows[1],['Hepsiburada','2026-06-18',0,0,'Tam gün']);
 assert.ok(tables.at(-1).rows.some(([,note])=>note==='Toplamlar doğrulanmış kayıtları içerir.'));
 const onlyLimited=salesChart([{...report.series[0],days:report.series[0].days.slice(0,1)}]);
 assert.match(onlyLimited,/Grafik için aktarılmış günlük veri bulunmuyor/);
 assert.doesNotMatch(onlyLimited,/<svg|₺0,00|report-data-table/);
 const knownPart={date:'2026-06-17',coverageLimited:true,partial:true,turnoverCents:7982500,orders:2};
 const partlyCovered={...report,series:[{...report.series[0],days:[knownPart]}]};
 assert.match(analysisResult(partlyCovered),/17\.06\.2026 · ₺79\.825,00 · Kısmi veri/);
 assert.deepEqual(reportTables(partlyCovered)[1].rows[0],['Hepsiburada','2026-06-17',79825,2,'Kısmi veri']);
});
test('Dışa aktarımda para sayısal, bilinmeyen adet boş ve formül girdisi metin kalır',()=>{
 const r={company:{name:'Örnek'},from:'2026-10-01',to:'2026-10-09',previousFrom:'2026-09-01',previousTo:'2026-09-09',generatedAt:'2026-10-09',availableChannels:1,selectedChannels:6,totals:{netCents:100,orders:1,averageCents:100},previousTotals:null,series:[],notes:[],products:[{channel:'Koçtaş',sku:'=HYPERLINK("x")',title:'Ürün',quantity:1,netQuantity:1,grossCents:100,cancelCents:0,returnCents:0,netCents:100}],payments:{items:[]}};
 r.payments.earningsItems=[{channel:'idefix',expectedCents:12000}];
 const tables=reportTables(r);assert.equal(tables[2].rows[0][1],'=HYPERLINK("x")');assert.equal(tables[2].rows[0][4],1);assert.equal(tables[2].rows[0][5],1);assert.equal(tables[0].rows[0][2],null);
 assert.ok(!tables.some(table=>table.name==='Sipariş Hakedişi'));assert.deepEqual(tables.find(table=>table.name==='Hakedişler').rows,[]);
});
