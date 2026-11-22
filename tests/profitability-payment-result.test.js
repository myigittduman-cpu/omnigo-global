import assert from 'node:assert/strict';
import test from 'node:test';
import {profitabilityBreakdown,profitabilityHeaders,profitabilityRows} from '../public/profitability-table.js';
import {documentOutputActions} from '../public/document-output-actions.js';

test('İptal durumu diğer sipariş ve iade satırlarına taşınmaz',()=>{
 const rows=profitabilityRows(['cancelled','returned','reconciled'].map(status=>({status,channel:'n11'})));
 assert.match(rows[0],/<tr class="order-row-cancelled">/);
 assert.match(rows[0],/İptal edildi/);
 assert.doesNotMatch(rows.slice(1).join(''),/order-row-cancelled/);
});

test('Son kalemde yalnız doğrulanmış ödeme görünür, bilinmeyen tutar sıfır olmaz',()=>{
 const order={channel:'Pazarama',purchaseCents:10000};
 const pending=profitabilityRows([{...order,status:'financials_pending',afterDeductionsCents:null}])[0];
 assert.ok(profitabilityHeaders.includes('Hesaba geçecek tutar'));
 assert.match(pending,/Ekstre bekleniyor/);
 assert.doesNotMatch(pending,/Kesin hakediş/);
 assert.doesNotMatch(pending,/<dd>—|Komisyon indirimi|Satış ve sepet indirimleri|class="profit-order-payout"[^]*?<strong>/);
 assert.match(profitabilityBreakdown({...order,salesCents:0,status:'cancelled'}),/Sipariş kaydındaki satış tutarı<\/dt><dd>[^<]*0,00/);
 const settled=profitabilityRows([{...order,status:'reconciled',afterDeductionsCents:12345}])[0];
 assert.ok(settled.indexOf('Alış maliyeti')<settled.indexOf('Hesaba geçecek tutar'));
 assert.match(settled,/Hesaba geçecek tutar[\s\S]*123,45<\/strong>[\s\S]*Kesin hakediş · Ekstreyle doğrulandı/);
 assert.doesNotMatch(settled,/data-profit-details|aria-haspopup="dialog"|data-breakdown-pager/);
});

test('Ürün oranlarından hesaplanan komisyonun kaynağı ve kesin hakedişin durumu ayrı gösterilir',()=>{
 const order={channel:'n11',status:'financials_pending',salesCents:30000,
  commissionSource:'marketplace_line_rates',commissionRateBasisPoints:1400,commissionCents:4200};
 const detail=profitabilityBreakdown(order);
 assert.match(detail,/Efektif komisyon oranı/);
 assert.match(detail,/Ürünlerin pazaryeri oranlarıyla hesaplandı/);
 assert.match(detail,/Komisyon sonrası ara tutar[\s\S]*258,00<\/dd>/);
 assert.match(profitabilityRows([order])[0],/Komisyon hesaplandı · Ekstre bekleniyor/);
 assert.doesNotMatch(detail,/profit-payment-result|Pazaryerinin bildirdiği komisyon tutarı/);
 assert.match(profitabilityRows([order])[0],/Komisyon hesaplandı · Ekstre bekleniyor/);
});

test('Belge işlemleri aynı sırada, izinli işlemlerle ve bağlı belge anahtarıyla oluşturulur',()=>{
 const controls=documentOutputActions({share:{attr:'data-share'},pdf:{attr:'data-pdf',value:'a"b'},print:{attr:'data-print',disabled:true}});
 assert.match(controls,/data-pdf="a&amp;quot;b"|data-pdf="a&quot;b"/);
 assert.match(controls,/PDF İndir[\s\S]*data-print disabled[\s\S]*Yazdır[\s\S]*WhatsApp ile paylaş/);
 assert.equal(documentOutputActions({}), '');
 assert.doesNotMatch(documentOutputActions({print:{attr:'data-print'}}),/WhatsApp|PDF İndir/);
});
