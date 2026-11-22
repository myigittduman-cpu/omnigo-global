<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,DeliverySchema,DeliveryMapper,DeliveryCipher,DeliveryRepository,DeliveryDocuments,HttpError,BackupArchive,SupportSchema,NotificationSchema};
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,'HTTP '.$status);return;}throw new RuntimeException('İşlem reddedilmeli.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));SupportSchema::migrate($db);NotificationSchema::migrate($db);DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);DeliverySchema::install($db);
$company=(new Repository($db))->createCompany('Teslimat test');$other=(new Repository($db))->createCompany('Diğer');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['admin@test.invalid','Test','unused','admin']);$user=$db->insertId();
$path=tempnam(sys_get_temp_dir(),'delivery-');$key=random_bytes(32);file_put_contents($path,$key);
try{
 $cipher=new DeliveryCipher($path);$repo=new DeliveryRepository($db,$cipher);$docs=new DeliveryDocuments($db,$cipher,$repo);
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_idefix',?,?)",[$company,json_encode(['deliveryLastPageAt'=>'2026-10-01T00:00:00Z','recentOrders'=>['lastPageAt'=>'2026-10-17T12:00:00Z']]),gmdate('c')]);
 check($repo->listing($company,'all','all','all',0)['sync']['idefix']['lastSuccessAt']==='2026-10-17T12:00:00Z','Boş başarılı kontrol eski sipariş tarihi gibi gösterilmemeli');
 $raw=['order_id'=>'K-123','order_state'=>'SHIPPING','created_date'=>'2026-10-10T00:00:00Z','customer'=>['firstname'=>'Sipariş','lastname'=>'Sahibi','shipping_address'=>['firstname'=>'Başka','lastname'=>'Alıcı','phone'=>'05000000000','street_1'=>'Teslimat sokağı 1','city'=>'Çankaya','state'=>'ANKARA'],'billing_address'=>['street_1'=>'FATURA ADRESİ']],
 'order_lines'=>[['order_line_state'=>'SHIPPING','product_title'=>'Bulaşık makinesi','offer_sku'=>'TEST-1','quantity'=>2]]];
 $item=DeliveryMapper::koctas($raw);check($item['buyerName']==='Sipariş Sahibi'&&$item['city']==='Ankara'&&!$item['issues'],'Koçtaş kişi ve il');
 check(!str_contains(json_encode($item,JSON_UNESCAPED_UNICODE),'FATURA')&&!str_contains(json_encode($item),'Başka'),'Alıcı ve fatura verisi taşınmamalı');
 foreach(['KIRIKKALE'=>'Kırıkkale','KIRŞEHİR'=>'Kırşehir','ESKISEHIR'=>'Eskişehir','Nevşehir'=>'Nevşehir','KAYSERİ'=>'Kayseri'] as $value=>$city)check(DeliveryMapper::city($value)===$city,'Türkçe il eşleştirme');
 $unknown=$raw;unset($unknown['customer']['firstname'],$unknown['customer']['lastname'],$unknown['customer']['shipping_address']['street_1']);check(count(DeliveryMapper::koctas($unknown)['issues'])===2,'Eksik kişi/adres alıcıdan veya faturadan doldurulmaz');
 $n11=['orderNumber'=>'N-1','id'=>33,'customerfullName'=>'Sipariş Sahibi','shipmentPackageStatus'=>'Picking','shippingAddress'=>['fullName'=>'Başka Alıcı','gsm'=>'05000000000','city'=>'Kayseri','district'=>'Talas','address'=>'Teslimat sokağı 2'],'billingAddress'=>['fullName'=>'FATURA KİŞİSİ'],
 'lines'=>[['productName'=>'Fırın','stockCode'=>'TEST-2','quantity'=>1,'orderItemLineItemStatusName'=>'Picking']]];
 $n=DeliveryMapper::n11($n11);check($n['buyerName']==='Sipariş Sahibi'&&$n['city']==='Kayseri'&&!$n['issues'],'n11 kişi/teslimat');
 $item['recipientName']='Başka Alıcı';$n['recipientName']='Başka Alıcı';
 $repo->ingest($company,$item);$repo->ingest($company,$item);$repo->ingest($other,$item);$repo->ingest($company,$n);
 $row=$db->one('SELECT * FROM delivery_orders WHERE company_id=? AND provider=?',[$company,'Koçtaş']);$id=(int)$row['id'];check((int)$row['version']===1&&!str_contains($row['payload'],'05000000000'),'Tekrarsız ve şifreli kayıt');
 try{$cipher->open($other,'order:Koçtaş:K-123',$row['payload']);throw new LogicException('Yanlış firma çözdü.');}catch(RuntimeException){}
 $listing=$repo->listing($company,'Ankara','all','pending',0);check(count($listing['items'])===1,'İl ve firma filtresi');
 $request=['city'=>'Ankara','deliveryDate'=>'2026-10-11','requestId'=>str_repeat('a',32),'orders'=>[['id'=>$id,'version'=>1]]];
 rejects(fn()=>$docs->create($other,$user,$request),404);
 rejects(fn()=>$docs->create($company,$user,[...$request,'city'=>'Kayseri']),409);
 rejects(fn()=>$docs->create($company,$user,[...$request,'orders'=>[['id'=>$id,'version'=>2]]]),409);
 $selection=new Omnigo\DeliverySelection($db,$repo);
 $snapshot=$selection->snapshot($company,$request);
 check($selection->snapshot($company,(array)json_decode(json_encode($request,JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR))===$snapshot,'HTTP iç içe JSON nesneleri PDF seçimini korur');
 check($snapshot['orders'][0]['buyerName']==='Sipariş Sahibi','PDF güncel teslimat bilgileri');
 check($selection->snapshot($company,$request)===$snapshot,'PDF yeniden indirilebilir');
 check((int)$db->one('SELECT COUNT(*) AS n FROM delivery_documents')['n']===0,'Doğrudan PDF arşiv oluşturmaz');
 rejects(fn()=>$selection->snapshot($other,$request),404);
 rejects(fn()=>$selection->snapshot($company,[...$request,'city'=>'Kayseri']),409);
 rejects(fn()=>$selection->snapshot($company,[...$request,'orders'=>[['id'=>$id,'version'=>2]]]),409);
 rejects(fn()=>$selection->snapshot($company,[...$request,'orders'=>array_fill(0,251,['id'=>$id,'version'=>1])]),400);
 $doc=$docs->create($company,$user,$request);check($docs->create($company,$user,$request)['id']===$doc['id'],'Çift tıklama aynı evrak');
 check($selection->snapshot($company,$request)['orders'][0]['id']===$id,'Eski arşiv kaydı yeni PDF indirmeyi engellemez');
 check($repo->listing($company,'Ankara','all','all',0,250)['cities'][1]['count']===1,'Şehir sayacı daha önce indirilmiş aktif siparişi de sayar');
 check(count($repo->listing($company,'Ankara','all','pending',0)['items'])===0,'Hazırlanan evrak bekleyenlerden çıkar');
 rejects(fn()=>$docs->create($company,$user,[...$request,'requestId'=>str_repeat('b',32)]),409);
 rejects(fn()=>$docs->create($company,$user,[...$request,'deliveryDate'=>'2026-10-12']),409);
 rejects(fn()=>$docs->get($other,$doc['id']),404);
 check($docs->get($company,$doc['id'])['orders'][0]['buyerName']==='Sipariş Sahibi','Arşiv doğru şifre çözümü');
 $changed=$item;$changed['address']='Yeni teslimat adresi';$repo->ingest($company,$changed);$changedRow=$repo->listing($company,'Ankara','all','pending',0)['items'][0];check($changedRow['version']===2&&$changedRow['documentId']===null,'Adres değişimi yeni sürüm');
 check($docs->get($company,$doc['id'])['orders'][0]['address']===$item['address'],'Arşiv değişmez');
 $changed['active']=false;$repo->ingest($company,$changed);check(!$repo->listing($company,'Ankara','all','all',0)['items'],'İptal/teslim edilmiş kayıtlar aktif listeden çıkar');
 rejects(fn()=>$selection->snapshot($company,[...$request,'orders'=>[['id'=>$id,'version'=>3]]]),409);
 rejects(fn()=>$docs->create($company,$user,[...$request,'requestId'=>str_repeat('c',32),'orders'=>[['id'=>$id,'version'=>3]]]),409);
 $missing=DeliveryMapper::koctas($unknown);$missing['recipientName']='Başka Alıcı';$missing['externalId']='K-456';$repo->ingest($company,$missing);$mid=(int)$db->one('SELECT id FROM delivery_orders WHERE external_id=?',['K-456'])['id'];
 check(count($repo->listing($company,'Diğer / Kontrol','all','all',0)['items'])===1,'Eksik kişi/adres kontrol listesine yönlenir');
 // The incomplete order is no longer in Ankara, so a stale city selection is a conflict.
 rejects(fn()=>$docs->create($company,$user,[...$request,'requestId'=>str_repeat('d',32),'orders'=>[['id'=>$mid,'version'=>1]]]),409);
 rejects(fn()=>$selection->snapshot($company,[...$request,'orders'=>[['id'=>$mid,'version'=>1]]]),409);
 foreach(['admin','client'] as $role)check(!str_contains(json_encode((new Repository($db))->dashboard(['role'=>$role,'id'=>1,'company_id'=>$company],$company,'2026-10')),'05000000000'),'Genel dashboard kişisel teslimat bilgisi içermez');
 Omnigo\RecentOrderSchema::install($db);
 $backup=BackupArchive::read(BackupArchive::create($db,$key),$key);check(isset($backup['tables']['delivery_orders'],$backup['tables']['delivery_documents'],$backup['tables']['recent_orders']),'Yeni şifreli tablolar yedekte');
 echo "Teslimat: kişi/adres ayrımı, il filtreleri, şifreleme, firma izolasyonu, iptal, sürüm kontrolü, idempotent arşiv ve yedek testleri geçti.\n";
}finally{unlink($path);}
