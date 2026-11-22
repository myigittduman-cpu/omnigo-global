import test from 'node:test';
import assert from 'node:assert/strict';
import {operationNotificationDetail} from '../public/operation-notification.js';
import {healthResult} from '../public/connection-health.js';
test('Mutabakat ayrıntısı referansı kaçışlar ve kuruşları açık gösterir',()=>{
 const html=operationNotificationDetail({events:['reconciliation_issue'],provider:'n11',createdAt:'2026-10-16T18:00:00Z',reconciliation:{orderCount:2,total:1,items:[{area:'orders',type:'amount',reference:'<img src=x onerror=alert(1)>',expected:[10001,20,10,1],actual:[10000,0,0,1]}]}});
 assert.ok(html.includes('100,01')&&html.includes('İptal')&&html.includes('İade'));assert.ok(!html.includes('<img'));assert.ok(html.includes('&lt;img'));
});
test('Bağlantı kontrolü yalnız tamamlanmış mutabakatı sağlıklı gösterir',()=>{
 const report={company:{id:1,name:'Örnek'},page:0,checkedAt:'2026-10-16T18:00:00Z',hasMore:false,items:[{companyId:1,companyName:'Örnek',channel:'n11',storeName:'Örnek',status:'ready',salesPublished:true,reconciliation:{state:'waiting',orderCount:0,counts:{}}}]};
 const html=healthResult(report);assert.ok(html.includes('Tamamlanmış kontrol bekleniyor'));assert.ok(!html.includes('Kayıtlar eşleşiyor'));
});
