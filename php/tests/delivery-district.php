<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{DeliveryDistrict,DeliveryMapper,OrderDetailMapper,TurkeyCities,TurkeyDistricts};

$cities=array_keys(TurkeyDistricts::BY_PROVINCE);
if(array_diff(TurkeyCities::ALL,$cities)||count($cities)!==81)throw new RuntimeException('İl eşlemesi eksik.');
$cases=[
    ['Ankara',['ANKARA'],'Örnek Mahallesi, 06100, YENİMAHALLE','Yenimahalle'],
    ['Ankara',['ANKARA'],'Örnek Sk. No 4, ALTINDAG / Ankara','Altındağ'],
    ['Kırıkkale',['KIRIKKALE'],'Örnek Sk. No 3, OVACIK, 71000, MERKEZ','Merkez'],
    ['Ankara',['ANKARA'],'Yenimahalle Caddesi, Örnek Sitesi / Ankara',''],
    ['Ankara',['ANKARA'],'Örnek Sokak, Kadıköy / Ankara',''],
    ['Ankara',['ANKARA','MAMAK'],'Yenimahalle Caddesi','MAMAK'],
    ['Samsun',[''],'Örnek Mahallesi, 19 Mayıs, Samsun','19 Mayıs'],
    ['İzmir',['Konak'],'Bornova Sokak, 35000, İZMİR','Konak'],
];
foreach($cases as [$city,$fields,$address,$expected]){
    if(DeliveryDistrict::resolve($city,$fields,$address)!==$expected)throw new RuntimeException('İlçe ayrıştırma başarısız: '.$city);
}
foreach([['Ankara','Yenimahalle'],['Yenimahalle','Ankara']] as [$city,$state]){
    $raw=['order_id'=>'QA','customer'=>['shipping_address'=>['city'=>$city,'state'=>$state,'street_1'=>'Örnek Sokak']]];
    $order=DeliveryMapper::koctas($raw);
    if($order['city']!=='Ankara'||$order['district']!=='Yenimahalle'||OrderDetailMapper::koctas($raw,$order)['province']!=='Ankara')throw new RuntimeException('Koçtaş il ve ilçe alanları yanlış.');
}
echo "Teslimat ilçe doğrulaması, geçmiş adres ve Koçtaş alan sırası geçti.\n";
