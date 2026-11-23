import assert from 'node:assert/strict';
import test from 'node:test';
import {profitabilitySaleAmount} from '../public/profitability-sale-amount.js';
import {profitabilityRows,profitabilityBreakdown} from '../public/profitability-table.js';
import {profitabilityDocumentTable} from '../public/profitability-document.js';
import {upcomingPayments} from '../public/upcoming-payments.js';

const row={orderNumber:'TEST-1',orderedAt:'2026-11-19T12:00:00Z',channel:'Koçtaş',
 status:'financials_pending',salesCents:931942,products:[],orderPricing:{}};

test('Geçmiş siparişin doğrulanmış satış tutarı ekranda ve belgede kendi kaynağı ile gösterilir',()=>{
 const screen=profitabilityRows([row])[0];
 assert.match(screen,/₺9\.319,42<\/span><\/div><small>Sipariş kaydındaki ürün tutarı/);
 const data=profitabilityDocumentTable([row],'Koçtaş').rows[0];
 assert.equal(data[3],'9.319,42\nSipariş kaydındaki ürün tutarı');
 assert.equal(data[4],null);
 assert.equal(data.at(-1),'Ekstre bekleniyor');
 assert.deepEqual(profitabilitySaleAmount({...row,orderPricing:{storePriceCents:0}}),{cents:0,fromOrder:false});
 for(const status of ['cancelled','returned','sales_pending']){
  assert.equal(profitabilitySaleAmount({...row,status}).cents,null);
 }
});

test('Çok ürünlü siparişte her satırın kendi tutarı kullanılır',()=>{
 const detail=profitabilityBreakdown({...row,products:[{sku:'SKU-A',quantity:2,salesCents:50100},{sku:'SKU-B',quantity:1,salesCents:30200}]});
 assert.match(detail,/SKU-A[\s\S]*?₺501,00/);
 assert.match(detail,/SKU-B[\s\S]*?₺302,00/);
});

test('Manuel hakediş bildirimi gizli, giriş düğmesi yalnız uygun yöneticiye açık',()=>{
 const overview={items:[],today:'2026-11-20',manualEntryChannels:['PTT AVM','idefix'],manualChannels:[]};
 const render=(data,canManage)=>upcomingPayments(data,'all',[],null,'',{canManage});
 const reader=render(overview,false);
 assert.doesNotMatch(reader,/data-manual-payout-note|yaklaşan hakedişler elle girilir/);
 assert.doesNotMatch(reader,/data-upcoming-manual[ >]/);
 const manager=render({...overview,manualChannels:['PTT AVM']},true);
 assert.match(manager,/data-upcoming-manual[ >]/);
 assert.doesNotMatch(manager,/data-manual-payout-note|elle tahmin ekleyebilirsiniz/);
});
