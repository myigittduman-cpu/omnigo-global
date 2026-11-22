<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Omnigo\HepsiburadaPreview;

// Reduced synthetic contract from the verified SIT response, with no credentials or customer data.
$merchant = '12345678-1234-1234-1234-123456789012';
$item = ['merchantId' => $merchant, 'id' => 'synthetic-line', 'orderNumber' => '9912345678',
    'quantity' => 2, 'totalPrice' => ['amount' => 30000, 'currency' => 'TRY'], 'status' => 'Open',
    'sku' => 'HB-CATALOG-SKU', 'merchantSKU' => 'SELLER-SKU', 'name' => 'Test ürünü',
    'shippingAddress' => ['address' => 'SYNTHETIC-PRIVATE'], 'customerName' => 'SYNTHETIC-PRIVATE'];
$mapped = HepsiburadaPreview::map([$item], $merchant);
if ($mapped['items'][0]['sku'] !== 'SELLER-SKU') throw new RuntimeException('SIT merchantSKU alanı korunmalı.');
if ($mapped['items'][0]['amountCents'] !== 3000000 || $mapped['items'][0]['quantity'] !== 2) throw new RuntimeException('Satır toplamı adet ile tekrar çarpılmamalı.');
if (str_contains(json_encode($mapped), 'SYNTHETIC-PRIVATE')) throw new RuntimeException('Önizleme kişisel veri taşımamalı.');
echo "Hepsiburada SIT: satıcı ürün kodu, çoklu adet tutarı ve önizleme veri sınırı geçti.\n";
