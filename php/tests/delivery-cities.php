<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,DeliveryCipher,DeliveryRepository,DeliverySelection,DeliveryCities,HttpError};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
function denied(callable $fn,int $status):void{try{$fn();}catch(HttpError $error){check($error->getCode()===$status,$error->getMessage());return;}throw new RuntimeException('İşlem reddedilmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('m',32));$repo=new DeliveryRepository($db,$cipher);
$insert=function(string $city,int $company=1,bool $active=true,array $issues=[],string $provider='Koçtaş')use($db,$cipher):int{
 $external='sample-'.($db->one('SELECT COUNT(*) AS n FROM delivery_orders')['n']+1);
 $value=['provider'=>$provider,'externalId'=>$external,'orderNumber'=>$external,'buyerName'=>'Örnek Kişi','recipientName'=>'Örnek Alıcı','phone'=>'05000000000','address'=>'Sentetik adres '.$city,'city'=>$city,'createdAt'=>'2026-10-26T10:00:00+03:00','status'=>'Sevk bekliyor','active'=>$active,'products'=>[['title'=>'Örnek ürün','quantity'=>1]],'issues'=>$issues];
 if($issues)$value['phone']='';
 if(!$active){$value['cancellation']=['cancelledQuantity'=>1];$value['cancelledProducts']=$value['products'];}
 $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$provider,$external,$issues?'Diğer / Kontrol':$city,$active?1:-1,$cipher->seal($company,'order:'.$provider.':'.$external,$value),'hash',gmdate('c')]);return $db->insertId();
};
$ids=[];foreach(['Ankara','Kırıkkale','Kırşehir','Nevşehir','Kayseri','Eskişehir'] as $city)$ids[$city]=$insert($city);
$other=$insert('Ankara',2);$cancelled=$insert('Ankara',1,false);$insert('Eskişehir',1,false);$incomplete=$insert('Ankara',1,true,['Telefon eksik']);$market=$insert('Kayseri',1,true,[],'Hepsiburada');
// More than one result page: apply cities before pagination, not to one downloaded page.
for($i=0;$i<24;$i++)$insert($i%2?'Ankara':'Eskişehir');
$cities=['Ankara','Kırıkkale','Kayseri'];$filters=['cities'=>$cities];$before=$db->all('SELECT * FROM delivery_orders ORDER BY id');
$first=$repo->listing(1,'all','all','all',0,10,false,$filters);$second=$repo->listing(1,'all','all','all',1,10,false,$filters);
$items=[...$first['items'],...$second['items']];check(count($items)===16&&$first['hasMore']&&!$second['hasMore'],'Üç il filtresi sayfalama öncesi uygulanır');
check(count(array_unique(array_column($items,'id')))===16,'Sayfalar arasında tekrar yok');
check(!array_diff(array_column($items,'city'),$cities),'Seçilmeyen il görünmez');
foreach([$other,$cancelled,$incomplete,$ids['Eskişehir']] as $id)check(!in_array($id,array_column($items,'id'),true),'Firma, iptal ve kontrol kaydı seçili illere sızmaz');
check(array_column($first['cities'],'count','name')['Eskişehir']===13,'Hızlı şehir kartları diğer illerin toplamını korur');
check($first['queues']['cancelled']===2,'İptal kartı tek şehir filtresindeki gibi genel sayacı korur');
$scoped=$repo->listing(1,'all','Hepsiburada','all',0,20,false,$filters);check(array_column($scoped['items'],'id')===[$market],'Pazaryeri filtresi birlikte çalışır');
check(!$repo->listing(1,'all','all','all',0,20,false,[...$filters,'to'=>'2026-10-25'])['items'],'Tarih filtresi birlikte çalışır');
check(!$repo->listing(1,'all','all','all',0,20,false,[...$filters,'query'=>'bulunmayan ürün'])['items'],'Arama filtresi birlikte çalışır');
check(count($repo->listing(1,'Ankara','all','all',0,20)['items'])===13,'Eski tek şehir davranışı aynı');
$selection=new DeliverySelection($db,$repo);$request=['city'=>'all','cities'=>$cities,'deliveryDate'=>'2026-10-27','orders'=>array_map(fn($city)=>['id'=>$ids[$city],'version'=>1],$cities)];
$snapshot=$selection->snapshot(1,$request);check($snapshot['cities']===$cities&&$snapshot['city']==='Ankara, Kırıkkale, Kayseri'&&count($snapshot['orders'])===3,'Üç il PDF/Excel anlık verisi');
check($selection->snapshot(1,(array)json_decode(json_encode($request)))===$snapshot,'HTTP JSON çoklu şehir seçimi');
foreach([[$ids['Eskişehir'],409],[$other,404],[$cancelled,409],[$incomplete,409]] as [$id,$status])denied(fn()=>$selection->snapshot(1,[...$request,'orders'=>[['id'=>$id,'version'=>1]]]),$status);
denied(fn()=>$selection->snapshot(1,[...$request,'orders'=>[['id'=>$ids['Ankara'],'version'=>9]]]),409);
foreach([[],['Ankara','İstanbul'],'Ankara',[['Ankara']],['all']] as $invalid)denied(fn()=>$repo->listing(1,'all','all','all',0,20,false,['cities'=>$invalid]),400);
check(DeliveryCities::parse(['Kayseri','Ankara','Ankara'])===['Ankara','Kayseri'],'Sabit sıra ve tekrarsız şehir seçimi');
check($before===$db->all('SELECT * FROM delivery_orders ORDER BY id'),'Listeleme ve evrak verisi kayıtları değiştirmez');
echo "Çoklu şehir: üç il, sayfalama, arama/tarih/pazaryeri, firma izolasyonu, iptal ve eksik kayıt engeli, PDF/Excel kapsamı ve salt okunur davranış geçti.\n";
