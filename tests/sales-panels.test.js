import test from 'node:test';
import assert from 'node:assert/strict';
import {activeSalesOverview,channelSalesMetrics} from '../public/sales-panels.js';
import {money} from '../public/ui.js';

test('Müşteri satış kartı seçili ayın satışını iptal düşülerek, iadeden ayrı gösterir',()=>{
 const html=channelSalesMetrics({name:'Koçtaş'},[
  {channel:'Koçtaş',month:'2026-09',grossCents:9000000,cancelCents:0,returnCents:0,orders:99},
  {channel:'Koçtaş',month:'2026-10',grossCents:10359855,cancelCents:100000,returnCents:50000,orders:4,integration:'koctas'},
 ],'2026-10');
 assert.ok(html.includes(money(10259855)));
 assert.match(html,/4 sipariş/);
 assert.ok(!html.includes(money(9000000)));
});

test('Veri yokluğu sıfır satış gibi gösterilmez; gerçek sıfır korunur',()=>{
 const channel={name:'n11'};
 const missing=channelSalesMetrics(channel,[],'2026-10');
 assert.match(missing,/Veri bekleniyor/);assert.ok(!missing.includes(money(0)));
 const zero=channelSalesMetrics(channel,[{channel:'n11',month:'2026-10',grossCents:0,cancelCents:0,returnCents:0,orders:0}],'2026-10');
 assert.ok(zero.includes(money(0)));assert.match(zero,/0 sipariş/);
 assert.match(zero,/Günlük veri bekleniyor/);
});

test('Aktif satış özeti pasif mağazayı gizler ve mağaza adını güvenle gösterir',()=>{
 const html=activeSalesOverview({month:'2026-10',data:{sales:[],channels:[{name:'<img onerror=alert(1)>',status:'active'},{name:'Pasif test mağazası',status:'inactive'}]}});
 assert.doesNotMatch(html,/Pasif test mağazası/);assert.match(html,/1 aktif mağaza/);
 assert.match(html,/&lt;img onerror=alert\(1\)&gt;/);
 assert.doesNotMatch(html,/<img onerror/);
});
