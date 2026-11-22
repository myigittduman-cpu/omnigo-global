<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{OrderDetailMapper,PazaramaInvoiceAmount,PazaramaPricing,PazaramaSalesMapper,PazaramaShipmentInvoice,ShipmentOrderMapper};

function checkInvoice(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function money(string $value): array
{
    return ['currency'=>'TL','value'=>$value];
}

function line(string $unit, int $quantity, string $seller, string $total, string $campaign): array
{
    return ['orderItemId'=>'11111111-0000-4000-8000-000000000001','orderItemStatus'=>3,
        'product'=>['name'=>'Örnek ürün','stockCode'=>'SKU'],'quantity'=>$quantity,
        'salePrice'=>money($unit),'discountAmount'=>money($seller),'totalPrice'=>money($total),
        'discountDescription'=>$campaign,'shipmentAmount'=>money('49.90')];
}

$real=line('10249',1,'200','10049','Satıcı kuponu (Pazarama İndirimi - 614.94 TL)');
$historical=line('42545',1,'200','42345','1000 TL üzeri 200 TL kupon (Pazarama İndirimi - 2127.25 TL)');
$multi=line('27990',4,'0','111960','(Pazarama İndirimi - 6717.6 TL)');
$undiscounted=line('93.19',2,'0','186.38','');
$tests=[
    'İki indirimli sipariş'=>[$real,943406],
    'Geçmiş kampanya'=>[$historical,4021775],
    'Dört adet için tek indirim'=>[$multi,10524240],
    'Açıkça indirimsiz iki ürün'=>[$undiscounted,18638],
    'Kaynakta açıkça sıfır pazaryeri katkısı'=>[line('100',2,'25','175','Mağaza kuponu (Pazarama İndirimi - 0 TL)'),17500],
    'Alıcı ürün tutarı sıfır'=>[line('100',1,'10','90','(Pazarama İndirimi - 90 TL)'),0],
    'Açıklaması eksik'=>[array_diff_key($undiscounted,['discountDescription'=>true]),null],
    'Açıklaması boş değer olarak geldi'=>[[...$undiscounted,'discountDescription'=>null],null],
    'Yalnızca serbest metin'=>[[...$real,'discountDescription'=>'Mağaza kuponu'],null],
    'Belirsiz pazaryeri kampanyası'=>[[...$real,'discountDescription'=>'Pazarama kampanyası'],null],
    'Satır toplamı zaten farklı'=>[[...$real,'totalPrice'=>money('9434.06')],null],
    'Geçersiz para birimi'=>[[...$real,'totalPrice'=>['currency'=>'EUR','value'=>'10049']],null],
    'Tutar ve kuruş çelişiyor'=>[[...$real,'totalPrice'=>[...money('10049'),'valueInt'=>943406]],null],
    'Satıcı indirimi eksik'=>[array_diff_key($real,['discountAmount'=>true]),null],
    'Satır toplamı eksik'=>[array_diff_key($real,['totalPrice'=>true]),null],
];
$delivery=['externalId'=>'11111111-0000-4000-8000-000000000002','createdAt'=>'2026-11-19T10:00:00+03:00',
    'buyerName'=>'','phone'=>'','address'=>'','city'=>'Ankara','district'=>'','status'=>'3'];
foreach ($tests as $label=>[$item,$expected]) {
    $raw=['items'=>[$item]];
    $details=OrderDetailMapper::pazarama($raw,$delivery)['products'][0];
    $row=ShipmentOrderMapper::pazarama($raw,$delivery)[0]['payload'];
    checkInvoice($row['invoiceCents']===$expected && $details['invoiceCents']===$expected && $details['amountCents']===$expected,$label);
    checkInvoice($row['quantity']===$item['quantity'] && $row['invoiceBasis']==='buyer_product' && $row['marketplaceAmounts']['shippingCents']===4990,$label.' sevkiyat bilgileri');
    $amounts=$row['marketplaceAmounts'];
    checkInvoice($expected===null ? !array_key_exists('netProductCents',$amounts) : $amounts['netProductCents']===$expected,$label.' tutar dökümü');
    checkInvoice(!array_key_exists('buyerCents',$amounts),$label.' ürün tutarı kargo dahil tahsilat sayılmaz.');
}
$raw=['currency'=>'TL','orderId'=>$delivery['externalId'],'orderDate'=>'2026-11-19 10:00','orderAmount'=>'10049','items'=>[$real]];
$financial=PazaramaSalesMapper::order($raw);
checkInvoice($financial['grossCents']===1004900 && $financial['products'][0]['grossCents']===1004900,'Satış aktarımının satır toplamı korunur.');
$p=PazaramaPricing::fromLine($real,1004900);
checkInvoice(PazaramaInvoiceAmount::fromPricing(array_diff_key($p,['platformDiscountReported'=>true]),1004900)===943406,'Eski pozitif platform kaydı.');
checkInvoice(PazaramaInvoiceAmount::fromPricing([...$p,'reportCents'=>943406],1004900)===null,'Çelişen rapor toplamı.');
checkInvoice(PazaramaInvoiceAmount::fromPricing([...$p,'platformDiscountReported'=>false],1004900)===null,'Çelişen indirim kaynağı.');
$old=PazaramaPricing::fromLine($undiscounted,18638);
checkInvoice(PazaramaInvoiceAmount::fromPricing($old,18638)===null,'Geçmiş kayıttaki varsayılan sıfır kampanya kanıtı sayılmaz.');
$linked=['source_key'=>'line'];
$legacy=['invoiceCents'=>1004900,'marketplaceReturn'=>['quantity'=>2,'returnedQuantity'=>1,'partial'=>true]];
checkInvoice(PazaramaShipmentInvoice::protectedRow($linked,$legacy),'İade senkronizasyonu faturaya yazamaz.');
checkInvoice(PazaramaShipmentInvoice::read($linked,$legacy)===null,'İade onayı eski satıcı brütünü doğrulamaz.');
$verified=[...$legacy,'marketplaceAmounts'=>['productCents'=>1024900,'sellerDiscountCents'=>20000,'platformDiscountCents'=>61494,'netProductCents'=>943406]];
checkInvoice(PazaramaShipmentInvoice::read($linked,$verified)===943406,'Kanıtlanan iki indirim bir kez düşülür.');
checkInvoice(PazaramaShipmentInvoice::read($linked,[...$legacy,'invoiceCents'=>0,'invoiceBasis'=>'buyer_product'])===0,'Doğrulanmış sıfır korunur.');
checkInvoice(PazaramaShipmentInvoice::read($linked,[...$verified,'invoiceCents'=>844500,'source'=>['locked'=>['invoiceCents']]])===844500,'Manuel fatura tutarı korunur.');
echo "Pazarama fatura tutarı, ürün satırı, indirimler ve eksik kaynak alanları doğrulandı.\n";
