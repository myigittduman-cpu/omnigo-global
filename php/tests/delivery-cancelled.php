<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,ShipmentSchema,NotificationSchema,DeliveryCipher,DeliveryRepository,DeliverySelection,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$cipher=DeliveryCipher::withKey(str_repeat('c',32));$repo=new DeliveryRepository($db,$cipher);
$sequence=0;
$save=fn(int $company,array $value)=>$db->transaction(fn()=>$repo->ingest($company,$value));
$order=function(string $provider,int $cancelled=0,bool $active=true)use(&$sequence){$id=(string)++$sequence;return ['provider'=>$provider,'externalId'=>'C-'.$id,'orderNumber'=>'C-'.$id,'createdAt'=>gmdate('c'),'active'=>$active,'city'=>'Ankara','buyerName'=>'Örnek kişi','phone'=>'05000000000','address'=>'Sentetik adres','issues'=>[],'status'=>$active?'Sevkiyat bekliyor':'İptal edildi','products'=>$active?[['title'=>'Geçerli ürün','quantity'=>2-$cancelled]]:[],'cancelledProducts'=>$cancelled?[['title'=>'İptal ürün','quantity'=>$cancelled]]:[],'cancellation'=>['quantity'=>2,'cancelledQuantity'=>$cancelled,'partial'=>$cancelled===1,'lines'=>[$id=>['quantity'=>2,'cancelledQuantity'=>$cancelled]]],'details'=>['products'=>[['lineId'=>$id,'title'=>'Ürün','quantity'=>2,'cancelledQuantity'=>$cancelled,'shippingState'=>$active?'pending':'cancelled']]]];};
$cancelledIds=[];
foreach(['Hepsiburada','n11','idefix','Pazarama','Koçtaş'] as $provider){
 $value=$order($provider);$save(1,$value);$row=$db->one('SELECT * FROM delivery_orders WHERE provider=? AND external_id=?',[$provider,$value['externalId']]);
 check(count($repo->listing(1,'all',$provider,'all',0)['items'])===1,'İlk aktarım aktif listede');
 $value['active']=false;$value['products']=[];$value['status']='İptal edildi';$value['cancellation']['cancelledQuantity']=2;
 foreach($value['cancellation']['lines'] as &$line)$line['cancelledQuantity']=2;unset($line);
 $value['cancelledProducts']=[['title'=>'İptal ürün','quantity'=>2]];$value['details']['products'][0]['cancelledQuantity']=2;$value['details']['products'][0]['shippingState']='cancelled';
 $save(1,$value);$save(1,$value);
 check(!$repo->listing(1,'all',$provider,'all',0)['items'],'Tam iptal aktif listeden çıkar');
 $listed=$repo->listing(1,'all',$provider,'cancelled',0)['items'];check(count($listed)===1&&$listed[0]['id']===(int)$row['id']&&$listed[0]['queueProducts'][0]['quantity']===2,'Aynı kayıt iptal listesinde tekrarsız');
 $cancelledIds[]=(int)$row['id'];
 try{(new DeliverySelection($db,$repo))->snapshot(1,['city'=>'Ankara','deliveryDate'=>date('Y-m-d'),'orders'=>[['id'=>(int)$row['id'],'version'=>$listed[0]['version']]]]);throw new RuntimeException('İptal evrağa eklenmemeli');}catch(HttpError $e){check($e->getCode()===409,'İptal PDF seçimi reddedilir');}
}
$partial=$order('Koçtaş',1);$save(1,$partial);
$p=$repo->listing(1,'all','Koçtaş','cancelled',0)['items'][0];check($p['active']&&$p['queueProducts']===[['title'=>'İptal ürün','sku'=>'','quantity'=>1]],'Kısmi iptal yalnız iptal miktarını gösterir');
check($repo->listing(1,'all','Koçtaş','all',0)['items'][0]['products'][0]['quantity']===1,'Kısmi iptalin kalan ürünü aktif kalır');
$save(2,$order('Koçtaş',2,false));$save(1,$order('Trendyol',2,false));$save(1,$order('Teknosa',2,false));
$delivered=$order('n11',0,false);$delivered['status']='Teslim edildi';$save(1,$delivered);
$returned=$order('idefix',0,false);$returned['status']='İade edildi';$save(1,$returned);
$superseded=$order('n11',2,false);$superseded['details']['superseded']=true;$save(1,$superseded);
$before=$db->all('SELECT * FROM delivery_orders ORDER BY id');
$list=$repo->listing(1,'all','all','cancelled',0,250,true);
check(count($list['items'])===7,'Firma, gizli pazaryeri, teslim/iade ve eski paket süzülür');
check(count(array_filter($list['items'],fn($item)=>$item['provider']==='Trendyol'))===1,'Etkin Trendyol iptali listede korunur');
check(!in_array('Teknosa',array_column($list['items'],'provider'),true),'Gizli Teknosa iptali görünür listeye girmez');
check($list['queues']['cancelled']===7,'İptal sayacı kısmi iptali bir kayıt sayar');
check($repo->listing(1,'all','n11','cancelled',0,50,true)['queues']['cancelled']===1,'Sayaç pazaryeri filtresine uyar');
check($repo->listing(1,'all','n11','all',0,50,true)['cities'][0]['count']===0,'Şehir sayacı seçili pazaryerindeki aktif listeyi sayar');
check(!$repo->listing(1,'Kayseri','all','cancelled',0,50,true)['items'],'Şehir filtresi');
check($before===$db->all('SELECT * FROM delivery_orders ORDER BY id'),'Liste salt okunur');
for($i=0;$i<56;$i++)$save(1,$order('Pazarama',2,false));
$first=$repo->listing(1,'all','Pazarama','cancelled',0,50,true);$last=$repo->listing(1,'all','Pazarama','cancelled',1,50,true);
check(count($first['items'])===50&&$first['hasMore']&&count($last['items'])===7&&!$last['hasMore'],'İptal listesi filtre sonrası doğru sayfalanır');
check($first['queues']['cancelled']===57&&$last['queues']['cancelled']===57,'İptal sayacı sayfa boyutuyla sınırlanmaz');
check(!array_intersect(array_column($first['items'],'id'),array_column($last['items'],'id')),'Sayfalar arasında mükerrer yok');
echo "İptal menüsü: beş pazaryeri geçişi, aynı kayıt, kısmi iptal, aktif/PDF ayrımı, firma/şehir, etkin Trendyol/gizli Teknosa, teslim-iade ayrımı ve sayfalama geçti.\n";
