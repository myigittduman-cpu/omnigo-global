import test from 'node:test';
import assert from 'node:assert/strict';
import {stats,channelTable} from '../public/views.js';
import {money} from '../public/ui.js';

const sale=(channel,refundsVerified)=>({channel,refundsVerified,grossCents:150000,cancelCents:10000,returnCents:2000,orders:3});
const dataFor=sales=>({sales,channels:sales.map(row=>({name:row.channel,status:'active'})),payments:[],summary:{refundsVerified:!sales.some(row=>row.refundsVerified===false),turnoverCents:sales.reduce((sum,row)=>sum+row.grossCents,0),cancelCents:sales.reduce((sum,row)=>sum+row.cancelCents,0),netCents:138000,orders:3}});
const renders=data=>[channelTable({data,user:{role:'admin'}}),channelTable({data,user:{role:'client'}})];

test('eksik iade uyarısı doğru pazaryerini iki rolde gösterir; tutarları değiştirmez',()=>{
 const data=dataFor([sale('PTT AVM',false),sale('idefix',true)]),before=structuredClone(data);
 for(const html of renders(data)){
  assert.ok(html.includes('PTT AVM için iade verileri henüz aktarılmadı.'));
  assert.ok(!html.includes('idefix iadeleri')&&!html.includes('idefix tutarından'));
  assert.ok(html.includes(money(data.sales.reduce((sum,row)=>sum+row.grossCents-row.cancelCents,0))));
 }
 assert.deepEqual(data,before);
});
test('birden fazla eksik pazaryeri birer kez listelenir',()=>{
 for(const html of renders(dataFor([sale('PTT AVM',false),sale('n11',false),sale('PTT AVM',false)])))
  assert.ok(html.includes('PTT AVM, n11 için iade verileri henüz aktarılmadı.'));
});
test('doğrulanmış iadelerde eksik aktarım uyarısı gösterilmez',()=>{
 for(const html of renders(dataFor([sale('idefix',true)]))) assert.ok(!html.includes('iade verileri henüz aktarılmadı'));
});
test('pazaryeri adı yoksa yanlış isim yerine genel açıklama gösterilir',()=>{
 const data=dataFor([]);data.summary.refundsVerified=false;
 for(const html of renders(data)) assert.ok(html.includes('Bazı pazaryerlerinin iade verileri henüz aktarılmadı.'));
});
test('iade açıklamasındaki pazaryeri adı HTML olarak çalıştırılamaz',()=>{
 for(const html of renders(dataFor([sale('<img onerror="alert(1)">',false)])))
  assert.ok(html.includes('&lt;img onerror=&quot;alert(1)&quot;&gt; için iade verileri'));
});

test('Bu Ay kartında eski kapsam açıklamaları yoktur',()=>{assert.doesNotMatch(stats(dataFor([sale('PTT AVM',false)])),/iade verileri henüz aktarılmadı|İptaller hariç satış toplamı|Komisyon ve giderler/);});
