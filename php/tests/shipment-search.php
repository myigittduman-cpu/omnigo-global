<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,ShipmentSchema,ShipmentRepository,ShipmentSearch,ShipmentOrdering,DeliveryCipher,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");$cipher=DeliveryCipher::withKey(str_repeat('s',32));$repo=new ShipmentRepository($db,$cipher);
$selection=['Ankara','Hepsiburada','2026-09'];
$base=['orderedAt'=>'2026-09-15T10:00:00+03:00','customerName'=>'Müşteri','recipientName'=>'Ayşe Çığ','phone'=>'905551234567','deliveryAddress'=>'Teslimat adresi','province'=>'Ankara','district'=>'Sincan','productName'=>'PROFİLO Buzdolabı','invoiceCents'=>2881160,'quantity'=>1,'invoiceName'=>'Örnek Firma','invoiceAddress'=>'İncek Fatura adresi','recipientEmail'=>'sample@example.test','customerType'=>'Kurumsal','packageStatus'=>'KARGOLANDI','privateField'=>'gizlitest'];
$insert=function(int $company,array $scope,array $payload)use($db,$cipher):void{
 $db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,ordered_at,created,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,...$scope,'',ShipmentOrdering::timestamp($payload),gmdate('c'),gmdate('c')]);$id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
};
$db->transaction(function()use($insert,$selection,$base){for($i=1;$i<=320;$i++)$insert(1,$selection,[...$base,'customerName'=>$i===1?'Son sayfadaki kayıt':($i%5===0?'Şule Işık':'Diğer Müşteri')]);});
foreach([[2,...$selection],[1,'Eskişehir','Hepsiburada','2026-09'],[1,'Ankara','n11','2026-09'],[1,'Ankara','Hepsiburada','2026-10']] as $scope)$insert(array_shift($scope),$scope,[...$base,'customerName'=>'Kapsam dışı']);
$db->run('UPDATE shipment_entries SET invoiced=1,cancelled=1,shipped=1 WHERE id=320');
check($repo->listing(1,$selection,0,'son sayfadaki')['items'][0]['id']===1,'Arama ilk sayfayı ve ilk 250 satırı aşmalı.');
$first=$repo->listing(1,$selection,0,'SULE ISIK');check($first['total']===64&&$first['totalRecords']===320&&count($first['items'])===10,'Türkçe harfler ve arama toplamı doğru olmalı.');
check($first['items'][0]['cancelled']&&$first['items'][0]['invoiced']&&$first['items'][0]['shipped'],'Arama üç kutucuğu da korumalı.');
$all=[];for($page=0;$page<7;$page++)$all=[...$all,...array_column($repo->listing(1,$selection,$page,'Şule Işık')['items'],'id')];
check(count($all)===64&&count(array_unique($all))===64,'Filtreli sayfalarda kayıt kaybı/tekrarı olmamalı.');
$sorted=$all;rsort($sorted);check($all===$sorted,'Aynı tarihli sonuçlar kararlı sırada olmalı.');
check($repo->listing(1,$selection,999,'şule')['page']===6,'Arama sayfası sınır dışındaysa son sayfaya dönmeli.');
foreach(['ayse cig','PROFILO buzdolabi','INCEK','+90 (555) 123 45 67','28.811,60','28811,60','15.09.2026','sample@example.test'] as $query)check($repo->listing(1,$selection,0,$query)['total']===320,'Görünen alan veya yazım eşleşmedi: '.$query);
foreach(['kapsam dışı','gizlitest',"' OR 1=1 --",'%_','olmayan kayıt'] as $query)check($repo->listing(1,$selection,0,$query)['total']===0,'Kapsam dışı/gizli veya özel karakterli arama eşleşmemeli.');
check($repo->listing(1,$selection,0,'   ')['total']===320,'Boş arama tüm seçili kayıtları getirmeli.');
foreach([['array'],str_repeat('a',121),"invalid\nquery"] as $value){try{ShipmentSearch::query($value);throw new RuntimeException('Geçersiz arama kabul edildi.');}catch(HttpError $error){check($error->getCode()===400,'Geçersiz arama 400 olmalı.');}}
echo "Sevkiyat araması: 320 kayıt, 250 satır sınırı, filtreli sayfalama, Türkçe harfler, telefon/fatura/tarih, kapsam ve gizli alan koruması geçti.\n";

check($repo->listing(1,$selection,0,'',['shipped'=>'yes'])['total']===1,'Sevk edilen filtrelenmeli.');
$body=json_decode('{"query":"sule","filters":{"shipped":"yes","dateFrom":"2026-09-15","dateTo":"2026-09-15"}}');
check($repo->listing(1,$selection,0,$body->query,$body->filters)['total']===1,'HTTP JSON içindeki filtre nesnesi kabul edilmeli.');
check($repo->export(1,$selection,$body->query,$body->filters)['total']===0,'Filtreyle eşleşen iptal belgeye girmemeli.');
check($repo->listing(1,$selection,0,'',['invoiced'=>'yes','cancelled'=>'no'])['total']===0,'Filtreler birlikte uygulanmalı.');
check($repo->listing(1,$selection,0,'sule',['invoiced'=>'yes','cancelled'=>'yes','shipped'=>'yes','customerType'=>'Kurumsal','dateFrom'=>'2026-09-15','dateTo'=>'2026-09-15'])['total']===1,'Arama, durum, tip ve tarih birlikte çalışmalı.');
check($repo->listing(1,$selection,0,'',['customerType'=>'Bireysel'])['total']===0,'Müşteri tipi tam eşleşmeli.');
check(count($repo->export(1,$selection)['items'])===319,'Dışa aktarma tüm sayfalardaki etkin satırları kapsamalı.');
check(count($repo->export(1,$selection,'sule',['shipped'=>'no'])['items'])===63,'Dışa aktarma arama ve filtreyi korumalı.');
check(!array_key_exists('privateField',$repo->export(1,$selection)['items'][0]),'Dışa aktarma gizli alanları içermemeli.');
$scope=['Kayseri','Hepsiburada','2026-09'];
foreach(['2026-09-14T23:59:59+03:00','2026-09-15T00:00:00+03:00','2026-09-15T23:59:59+03:00','2026-09-16T00:00:00+03:00'] as $date)$insert(1,$scope,[...$base,'orderedAt'=>$date]);
$dateItems=$repo->listing(1,$scope,0,'',['dateFrom'=>'2026-09-15','dateTo'=>'2026-09-15']);
check($dateItems['total']===2&&$dateItems['items'][0]['orderedAt']==='2026-09-15T23:59:59+03:00','Türkiye saatinde gün başı ve sonu dahil olmalı, en yeni önce gelmeli.');
check($repo->listing(1,$scope,0,'',['dateFrom'=>'2026-09-15'])['total']===3,'Tek taraflı başlangıç tarihi çalışmalı.');
check($repo->listing(1,$scope,0,'',['dateTo'=>'2026-09-15'])['total']===3,'Tek taraflı bitiş tarihi çalışmalı.');
foreach([['dateFrom'=>'2026-09-31'],['dateFrom'=>'2026-09-20','dateTo'=>'2026-09-01'],['invoiced'=>'evil'],['customerType'=>[]],['dateFrom'=>[]],['other'=>'yes']] as $filters){try{$repo->listing(1,$selection,0,'',$filters);throw new RuntimeException('Geçersiz filtre kabul edildi.');}catch(HttpError $error){check($error->getCode()===400,'Geçersiz filtre 400 olmalı.');}}
echo "Durum/tip/tarih filtreleri, Türkiye saati gün sınırları ve tüm sayfalardan güvenli dışa aktarma geçti.\n";

$allCities=['all','Hepsiburada','2026-09'];
check($repo->listing(1,$allCities)['total']===325,'Şehirler tek listede birleşmeli; diğer firma, ay ve pazaryeri dahil olmamalı.');
check($repo->listing(1,$allCities,0,'kapsam dışı')['total']===1,'Şehirler arası arama çalışmalı, diğer kapsamlar ayrılmalı.');
check($repo->listing(1,$allCities,0,'',['shipped'=>'yes'])['total']===1,'Tüm şehirlerde durum filtrelenmeli.');
check($repo->listing(1,$allCities,0,'',['dateFrom'=>'2026-09-16','dateTo'=>'2026-09-16'])['total']===1,'Tüm şehirlerde tarih filtresi çalışmalı.');
$export=$repo->export(1,$allCities);check($export['total']===324&&$export['city']==='all','Dışa aktarma tüm şehirlerdeki etkin kayıtları kapsamalı.');
$listed=[];for($page=0;$page<ceil(325/10);$page++)$listed=[...$listed,...$repo->listing(1,$allCities,$page)['items']];
check(array_column(array_filter($listed,fn($item)=>!$item['cancelled']),'id')===array_column($export['items'],'id'),'Birleşik sayfalarda belgeye uygun satırların sırası korunmalı.');
$times=array_map(fn($item)=>ShipmentOrdering::timestamp($item),$listed);$sorted=$times;rsort($sorted);check($times===$sorted,'Farklı şehirlerde en yeni sipariş her zaman üstte olmalı.');
check($repo->listing(1,$selection)['total']===320,'Tek şehir filtresi korunmalı.');
echo "Tüm şehirler: 325 kayıt, sayfalar arası tarih sırası, arama/filtre/dışa aktarma ve kapsam ayrımı geçti.\n";
