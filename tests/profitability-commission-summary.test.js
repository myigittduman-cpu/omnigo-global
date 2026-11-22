import test from 'node:test';
import assert from 'node:assert/strict';
import {profitabilityCommissionSummary as summary} from '../public/profitability-commission-summary.js';

const n11 = {channel:'n11', status:'financials_pending', salesCents:1889900,
  commissionSource:'marketplace_rate', commissionRateBasisPoints:1200, commissionCents:226788};

test('n11 siparişinde %12 komisyon, kesin hakediş beklenirken de hesaplanır', () => {
  const expected = {basisCents:1889900, rateBasisPoints:1200, commissionCents:226788,
    remainingCents:1663112, source:'marketplace_rate'};
  assert.deepEqual(summary(n11), expected);
  assert.deepEqual(summary({...n11, commissionCents:null, commissionDiscountCents:0}), expected);
  const order = {...n11, orderPricing:{storePriceCents:2000000,sellerDiscountCents:110100,buyerPaidCents:1750000},
    withholdingCents:10000, purchaseCents:1020000, afterDeductionsCents:1653112};
  assert.deepEqual(summary(order), expected);
  assert.equal(order.afterDeductionsCents,1653112);
});

test('kaynakta uyuşmayan n11 komisyonu veya belirsiz kampanya oranı ara tutara dönüştürülmez', () => {
  assert.equal(summary({...n11, commissionCents:180000}).remainingCents, null);
  assert.equal(summary({...n11, commissionDiscountCents:12000}).remainingCents, null);
  assert.equal(summary({...n11, commissionCents:null, commissionDiscountCents:12000}).commissionCents, null);
  assert.equal(summary({...n11, salesCents:null}).rateBasisPoints, 1200);
  assert.equal(summary({...n11, salesCents:null}).remainingCents, null);
  assert.equal(summary({...n11, commissionCents:0, commissionRateBasisPoints:0}).remainingCents,1889900);
});

test('farklı SKU oranları ve satır yuvarlamalarında kaydedilmiş toplam komisyon aynen kullanılır', () => {
  const manual = {status:'financials_pending', channel:'n11', commissionSource:'manual',
    salesCents:100000, commissionRateBasisPoints:1638, commissionCents:16375,
    products:[{sku:'A',quantity:1},{sku:'B',quantity:2}]};
  assert.equal(summary(manual).remainingCents,83625);
  assert.equal(summary({...manual,commissionCents:null}).remainingCents,null);
  const ptt = {...manual,channel:'PTT AVM',commissionSource:'marketplace_rate',orderPricing:{storePriceCents:110000}};
  assert.equal(summary(ptt).basisCents,110000);
  assert.equal(summary(ptt).remainingCents,null);
  assert.equal(summary({...ptt,salesCents:110000}).remainingCents,93625);
});

test('n11 efektif oranını toplam satış tutarına tekrar uygulamadan satır komisyonlarını kullanır', () => {
  const mixed = {...n11, salesCents:300003, commissionSource:'marketplace_line_rates',
    commissionCents:29000, commissionRateBasisPoints:967, commissionDiscountCents:0};
  assert.equal(summary(mixed).remainingCents,271003);
  assert.equal(summary(mixed).basisCents,300003);
  assert.equal(summary({...mixed,commissionCents:null}).remainingCents,null);
  assert.equal(summary({...mixed,commissionDiscountCents:15}).remainingCents,null);
  assert.equal(summary({...mixed,channel:'idefix'}).remainingCents,null);
});

test('KDV dahil komisyon ikinci kez vergilendirilmez ve yalnız KDV hariç bilgi tam kesinti sayılmaz', () => {
  const koctas = {...n11,channel:'Koçtaş',commissionSource:'marketplace_effective',salesCents:100000,
    commissionCents:12000,commissionNetCents:10000,commissionTaxCents:2000};
  assert.equal(summary(koctas).remainingCents,88000);
  const hb = {...koctas,channel:'Hepsiburada',commissionSource:'marketplace_net',
    commissionCents:null,commissionTaxCents:null};
  assert.deepEqual(summary(hb),{basisCents:null,rateBasisPoints:1200,commissionCents:null,
    remainingCents:null,source:'marketplace_net'});
});

test('Trendyol ve idefix kampanyalarında bildirilen komisyon korunur, çelişen matrah seçilmez', () => {
  const idefix = {...n11,channel:'idefix',commissionSource:'marketplace_effective',salesCents:922410,
    commissionCents:20498,commissionRateBasisPoints:222,orderPricing:{storePriceCents:1024900,sellerDiscountCents:0}};
  assert.equal(summary(idefix).commissionCents,20498);
  assert.equal(summary(idefix).remainingCents,null);
  assert.equal(summary({...idefix,orderPricing:{storePriceCents:1024900,sellerDiscountCents:102490}}).remainingCents,901912);
  const trendyol = {...idefix,channel:'Trendyol',commissionSource:'marketplace_sale',commissionRateBasisPoints:null};
  assert.equal(summary(trendyol).remainingCents,null);
  assert.equal(summary({...trendyol,orderPricing:{storePriceCents:922410}}).remainingCents,901912);
});

test('teyitli veya eski ekstrede ara tutar gerçek hakedişten ayrı kalır; iptal ve iadede gösterilmez', () => {
  const pazarama = {salesCents:2768200,commissionCents:277042,commissionSource:'marketplace_effective',
    commissionRateBasisPoints:1001,channel:'Pazarama',afterDeductionsCents:2466782};
  for (const status of ['reconciled','statement_stale']) {
    assert.equal(summary({...pazarama,status}).remainingCents,2491158);
  }
  for (const status of ['cancelled','returned','sales_pending','unknown']) assert.equal(summary({...n11,status}),null);
  assert.equal(summary({...n11, commissionRateBasisPoints:null, commissionCents:null}),null);
  assert.equal(summary({...n11, commissionRateBasisPoints:10001, commissionCents:-5}),null);
});

test('matrah ve oran doğrulandığında kuruş yuvarlaması güvenli tam sayı sınırında da aynıdır', () => {
  for(const [cents,points] of [[55,1000],[54,1000],[Number.MAX_SAFE_INTEGER,9876]]) {
    const expected=Number((BigInt(cents)*BigInt(points)+5000n)/10000n);
    assert.equal(summary({...n11,salesCents:cents,commissionRateBasisPoints:points,commissionCents:null}).commissionCents,expected);
  }
});
