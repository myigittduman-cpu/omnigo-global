<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\ShipmentDeliveryLocation;

function verifyLocation(string $reason,array $row,array $payload,array $packages,string $province,string $district): void
{
    $result=ShipmentDeliveryLocation::fields($row,$payload,$packages);
    if($result!==['province'=>$province,'district'=>$district]){
        throw new RuntimeException($reason.' '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}

$bucket=['province'=>'Ankara'];
$billing=['invoiceAddress'=>'Örnek Mahallesi / Çankaya / Ankara','invoiceProvince'=>'Ankara','invoiceDistrict'=>'Çankaya'];
$kayseri=['city'=>'Kayseri','district'=>'Melikgazi','details'=>$billing];
$ankara=['city'=>'Ankara','district'=>'Gölbaşı'];
$adiyaman=['city'=>'Adıyaman','district'=>'Gölbaşı'];
verifyLocation('Kaynak il boş depolanan değerden ve listede doldurulmuş lojistik kovasından ayrılmalı.',
    $bucket,['province'=>'',...$billing],[$kayseri],'Kayseri','Melikgazi');
verifyLocation('Paket ve gerçek teslimat ili yoksa Ankara lojistik grubundan il üretilmemeli.',
    $bucket,$billing,[],'','');
verifyLocation('Açıkça yazılmış ilçe ve il, farklı lojistik grubuna rağmen gerçek teslimat adresinden okunmalı.',
    $bucket,[...$billing,'deliveryAddress'=>'Örnek Cadde 7, Talas / Kayseri'],[],'Kayseri','Talas');
verifyLocation('Manuel belgenin girilmiş teslimat il ve ilçesi, paket olmadan da korunmalı.',
    $bucket,['province'=>'İzmir','district'=>'Konak','source'=>['kind'=>'manual'],...$billing],[],'İzmir','Konak');
verifyLocation('Sadece fatura adresi kullanılarak teslimat ili veya ilçesi tahmin edilmemeli.',
    [],$billing,[],'','');
verifyLocation('İki farklı ildeki adaş ilçe paketleri rastgele seçilmemeli.',
    $bucket,[],[$ankara,$adiyaman],'','');
verifyLocation('Kayıtlı il adaş ilçenin hangi ile ait olduğunu sınırlandırmalı.',
    $bucket,['province'=>'Adıyaman'],[$ankara,$adiyaman],'Adıyaman','Gölbaşı');
verifyLocation('İl adı ilçede tekrarlandığında diğer şehrin fatura adresinden sonuç üretilmemeli.',
    $bucket,['province'=>'Ankara','district'=>'ANKARA',...$billing],[],'Ankara','');
verifyLocation('Geçmiş teslimat adresinin sonundaki geçerli ilçe okunmalı.',
    $bucket,['province'=>'Ankara','district'=>'ANKARA','deliveryAddress'=>'Örnek Sk. 2, 06000, SİNCAN / ANKARA'],[],'Ankara','Sincan');
verifyLocation('Başka ilin ilçesi doğrulanmış olarak sunulmamalı.',
    $bucket,['province'=>'Ankara','district'=>'Kadıköy'],[],'Ankara','');
verifyLocation('Eski kaydın ilini kesin paket belirlediğinde onun ilçesi önde gelmeli.',
    $bucket,['district'=>'Kocasinan'],[$kayseri],'Kayseri','Melikgazi');
verifyLocation('Açıkça düzeltilmiş ilçe kaynak paketini geçersiz kılmalı.',
    $bucket,['province'=>'Kayseri','district'=>'Talas','source'=>['locked'=>['district']]],[$kayseri],'Kayseri','Talas');
verifyLocation('Elle boşaltılan ilçeyi teslimat adresinden yeniden doldurmamalı.',
    $bucket,['province'=>'Kayseri','district'=>'','deliveryAddress'=>'Örnek, Talas / Kayseri','source'=>['locked'=>['district']]],[$kayseri],'Kayseri','');
verifyLocation('Elle güncellenen adres ile eski kaynak paketi karıştırılmamalı.',
    $bucket,['province'=>'Ankara','deliveryAddress'=>'Örnek, Sincan / Ankara','source'=>['locked'=>['deliveryAddress']]],[$kayseri],'Ankara','Sincan');
verifyLocation('Kilitli boş il yerine lojistik il seçilmemeli.',
    $bucket,['province'=>'','source'=>['locked'=>['province']]],[$kayseri],'','');
verifyLocation('Aynı ilin farklı ilçelerini gösteren paketlerden rastgele bir ilçe seçilmemeli.',
    [],[],[$ankara,['city'=>'Ankara','district'=>'Mamak']],'Ankara','');
echo "Teslimat konumu için 16 kaynak, kilit ve adaş ilçe kontrolü geçti.\n";

