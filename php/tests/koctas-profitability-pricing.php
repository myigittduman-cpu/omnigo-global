<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{KoctasOrderMapper,ProfitabilityCalculator,ProfitabilityMarketplacePricing,ProfitabilityOrderPricing};

function verifyKoctasPrice(bool $condition,string $description): void
{
    if (!$condition) throw new RuntimeException($description);
}

$first=['order_line_id'=>'L1','order_line_state'=>'SHIPPING','quantity'=>1,'offer_sku'=>'A',
    'product_title'=>'Örnek ürün','price'=>'9319.42','price_unit'=>'9319.42','promotions'=>[],
    'shipping_price'=>'250.00','total_price'=>'9569.42','price_amount_breakdown'=>['parts'=>[
        ['amount'=>'9319.42','debitable_from_customer'=>true],
    ]]];
$second=['order_line_id'=>'L2','order_line_state'=>'SHIPPING','quantity'=>2,'offer_sku'=>'B','product_title'=>'İkinci ürün',
    'price'=>'175.00','price_unit'=>'100.00','shipping_price'=>'17.00','total_price'=>'192.00',
    'promotions'=>[['id'=>'basket-1','deduced_amount'=>'25.00','apportioned'=>true]],
    'price_amount_breakdown'=>['parts'=>[
        ['amount'=>'160.00','debitable_from_customer'=>true],['amount'=>'15.00','debitable_from_customer'=>false],
    ]]];
$raw=['order_id'=>'TEST-KOCTAS','order_state'=>'SHIPPING','currency_iso_code'=>'TRY','created_date'=>'2026-11-19T22:57:00+03:00',
    'order_lines'=>[$first,$second]];
$snapshot=['provider'=>'koctas','order_number'=>'TEST-KOCTAS','ordered_at'=>strtotime($raw['created_date'])];
$order=KoctasOrderMapper::map($raw);
$report=ProfitabilityCalculator::row($snapshot,$order,[]);
$item=$report['products'][0];
verifyKoctasPrice($item['salesCents']===931942 && $item['orderPricing']['storePriceCents']===931942
    && $item['orderPricing']['buyerPaidCents']===931942,'Kaynak birim teklif ile alıcı bileşenleri sevkiyattan bağımsız doğrulanır.');
$promo=$report['products'][1];
verifyKoctasPrice($promo['salesCents']===17500 && $promo['orderPricing']['storePriceCents']===20000
    && $promo['orderPricing']['buyerPaidCents']===16000 && $promo['orderPricing']['sellerDiscountCents']===null
    && $promo['orderPricing']['platformDiscountCents']===null,'Satıra dağıtılan promosyon adetle tekrar çarpılmaz veya bir tarafa yazılmaz.');
verifyKoctasPrice($report['salesCents']===949442 && $report['orderPricing']['storePriceCents']===951942
    && $report['orderPricing']['buyerPaidCents']===947942 && $report['afterDeductionsCents']===null,
    'Sipariş fiyatları, alıcıya düşen ürün tutarları ve kargo ayrı ele alınır.');

$historical=$order;
foreach($historical['products'] as &$product)unset($product['orderPricing']);
unset($product);
$old=ProfitabilityCalculator::row($snapshot,$historical,[]);
verifyKoctasPrice($old['orderPricing']===ProfitabilityOrderPricing::UNKNOWN && $old['products'][0]['salesCents']===931942
    && $old['products'][1]['salesCents']===17500,'Eski kaydın gerçek ürün fiyatı korunur, kampanya öncesi ve alıcı fiyatı türetilmez.');

foreach([
    ['promotions'=>[['id'=>'basket-1','deduced_amount'=>24]]], ['promotions'=>null],
    ['promotions'=>[['id'=>'p','deduced_amount'=>10],['id'=>'p','deduced_amount'=>15]]],
] as $change) {
    $prices=ProfitabilityMarketplacePricing::koctas([...$second,...$change]);
    verifyKoctasPrice($prices['storePriceCents']===null && $prices['buyerPaidCents']===16000,
        'Eksik veya çelişen promosyonlar teklif fiyatı ispatı olmamalı.');
}
$confirmed=ProfitabilityMarketplacePricing::koctas([...$second,'origin_unit_price'=>110,'price_amount_breakdown'=>null]);
verifyKoctasPrice($confirmed['storePriceCents']===22000 && $confirmed['buyerPaidCents']===null,
    'Orijinal teklif doğrudan bildirilirse korunur, alıcının payı varsayılmaz.');
$invalid=ProfitabilityMarketplacePricing::koctas([...$first,'price_amount_breakdown'=>['parts'=>[
    ['amount'=>'9569.42','debitable_from_customer'=>true],
]]]);
verifyKoctasPrice($invalid['buyerPaidCents']===null,'Kargoyu içeren bileşenler ürün fiyatı olarak gösterilemez.');

$returned=KoctasOrderMapper::map([...$raw,'order_lines'=>[[...$second,'refunds'=>[[
    'refund_state'=>'REFUNDED','quantity'=>1,'amount'=>'75.00',
]]]]]);
$partial=ProfitabilityCalculator::row($snapshot,$returned,[]);
verifyKoctasPrice($partial['products'][0]['salesCents']===10000 && $partial['orderPricing']===ProfitabilityOrderPricing::UNKNOWN,
    'Kısmi iadede kalan gerçek satır değeri korunur, kupon oranlanmaz.');
$cancelled=KoctasOrderMapper::map([...$raw,'order_state'=>'CANCELED','order_lines'=>[[...$first,'order_line_state'=>'CANCELED']]]);
$void=ProfitabilityCalculator::row($snapshot,$cancelled,[]);
verifyKoctasPrice($void['status']==='cancelled' && $void['salesCents']===0 && $void['orderPricing']===ProfitabilityOrderPricing::UNKNOWN,
    'İptal edilen sipariş eski aktif fiyatları geri getirmez.');
echo "Koçtaş sipariş kaydı, mağaza ve alıcı fiyatı, promosyon, adet, kargo, geçmiş kayıt ve iade kontrolleri geçti.\n";
