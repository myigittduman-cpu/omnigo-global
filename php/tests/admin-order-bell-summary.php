<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{AdminNotificationInbox,Database,DeliveryCipher,DeliverySchema,NotificationFeed,NotificationSchema,OrderList,OrderNotificationOrder,RecentOrderSchema,ShipmentSchema,SupportSchema};
function verify(bool $value,string $reason):void{if(!$value)throw new RuntimeException($reason);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);RecentOrderSchema::install($db);DeliverySchema::install($db);ShipmentSchema::install($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Test Admin','unused','admin',NULL),(2,'client@example.test','Test Mağaza','unused','client',1)");
$cipher=DeliveryCipher::withKey(str_repeat('z',32));$admin=['id'=>1,'role'=>'admin'];$expected=[];
foreach([1,2] as $company)foreach(OrderList::PROVIDERS as $provider=>$label){
    $external=$provider==='pazarama'?'ornek-uuid':'001234';
    $number=$provider==='pazarama'?'000789':$external;
    $packageId=in_array($provider,['n11','hepsiburada','idefix'],true)?$external.':paket':$external;
    $order=['provider'=>$label,'externalId'=>$packageId,'orderNumber'=>$number];
    $ref=$provider==='pazarama'?null:json_encode(OrderNotificationOrder::reference($order));
    $db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,'order',?,?)",[$company,$provider,hash('sha256',$number),$ref,gmdate('c')]);
    $notice=$db->insertId();
    $amount=$provider==='trendyol'?0:$company*12345;
    $sale=['externalId'=>$external,'grossCents'=>$amount];
    if($provider==='idefix')$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_'.$provider,json_encode($sale),gmdate('c')]);
    elseif($provider!=='koctas')$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,$provider,$external,json_encode($sale),gmdate('c')]);
    $buyer=$company.' '.$provider;
    $payload=$cipher->seal($company,'order:'.$label.':'.$packageId,[...$order,'buyerName'=>$buyer,'phone'=>'gizli-telefon','address'=>'gizli-adres']);
    $db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$label,$packageId,'Ankara',1,$payload,'hash',gmdate('c')]);
    $expected[$notice]=['companyId'=>$company,'buyerName'=>$provider==='pttavm'?null:$buyer,'amountCents'=>$provider==='koctas'?null:$amount];
}
$inbox=new AdminNotificationInbox($db,$cipher);
$items=$inbox->listing($admin,'all','marketplaces')['items'];
verify(count($items)===count($expected),'İki mağazanın beklenen bildirimleri gelmeli.');
foreach($items as $item){
    $expectedItem=$expected[$item['id']];
    foreach($expectedItem as $field=>$value)verify(($item[$field]??null)===$value,$item['provider'].' alanı hatalı: '.$field);
    verify(!str_contains(json_encode($item),'gizli-'),'Zil telefonu veya adresi içermemeli.');
}
$feed=new NotificationFeed($db,$cipher);
$single=$feed->listing(1,1,0,'all','marketplaces')['items'];
foreach($single as $item)verify(($item['buyerName']??null)===$expected[$item['id']]['buyerName']&&$expected[$item['id']]['companyId']===1,'Mağaza kapsamı karıştı.');
$client=$feed->listing(2,1,0,'all','marketplaces')['items'];
verify(count($client)===0,'Genel sipariş bildirimini müşteri hesabı almamalı.');
echo "Yedi pazaryeri, iki mağaza, eski Pazarama, sıfır ve eksik tutar, müşteri gizliliği doğrulandı.\n";
