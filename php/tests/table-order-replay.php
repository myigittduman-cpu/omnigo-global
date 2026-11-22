<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,ShipmentSchema,TableOrderReplay};

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(10,'test@example.invalid','Test','unused','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('t',32));
$at=1730419200;
$old=gmdate('c',$at-60);$current=gmdate('c',$at);
$storeLine=function(int $company,string $key,?string $deleted,string $hiddenAt,bool $explicit=true)use($db,$cipher,$old){
    $db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated,source_key,removed_at)
        VALUES(?,'Ankara','Hepsiburada','2024-11','',?,?,?,?)",[$company,$old,$old,hash('sha256','Hepsiburada:'.$key),$deleted]);
    $id=$db->insertId();
    $mark=['at'=>$hiddenAt,'userId'=>10];
    $payload=['invoiceCents'=>12345,'manualStatusOverride'=>['enabled'=>true],'documents'=>['invoice'=>['filename'=>'Örnek.pdf']],
        'documentTracking'=>['removed'=>['delivery'=>$mark,'invoice'=>$mark],'delivered'=>$mark]];
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
    if($deleted&&$explicit)$db->run("INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(10,?,'shipment.delete',?,?)",[$company,$id,$deleted]);
    return $id;
};
$a=$storeLine(1,'bir',$old,$old);$b=$storeLine(2,'bir',$old,$old);
$invoice=$storeLine(1,'iki',null,$old);$newer=$storeLine(1,'üç',$current,$current);
$unrelated=$storeLine(1,'dört',$old,$old);$retired=$storeLine(1,'beş',$old,$old,false);
$newInvoice=$storeLine(1,'altı',null,$current);
$item=['order'=>['externalId'=>'sip-1'],'deliveries'=>[['shipmentItems'=>array_map(fn($k)=>['key'=>$k],['bir','iki','üç','beş','altı','bir'])]]];
$run=fn($table,$provider='hepsiburada')=>$db->transaction(fn()=>TableOrderReplay::restore($db,1,$table,$provider,$item,$at,$cipher));
$read=fn($id)=>$db->one('SELECT * FROM shipment_entries WHERE id=?',[$id]);
$payload=fn($id)=>$cipher->open(1,'shipment:'.$id,$read($id)['payload']);
$check=static function($ok){if(!$ok)throw new RuntimeException('Tablo kapsamı veya sonradan yapılan düzenleme korunamadı.');};
$before=$payload($invoice);
$check($run('shipmentInvoices')===1 && $run('shipmentInvoices')===0);
unset($before['documentTracking']['removed']['invoice']);
$check($payload($invoice)===$before && $read($a)['removed_at']===$old && $payload($newInvoice)['documentTracking']['removed']['invoice']['at']===$current);
$check($run('deliveries')===0 && $run('cityProducts')===0 && $run('shipments','n11')===0);
$check($run('shipments')===1 && $run('shipments')===0);
$check($read($a)['removed_at']===null && isset($payload($a)['documentTracking']['removed']['invoice']));
foreach([$b,$newer,$unrelated,$retired] as $id)$check($read($id)['removed_at']!==null);
$check((int)$db->one("SELECT COUNT(*) n FROM audit WHERE action='shipment.table_replay'")['n']===2);
echo "İki mağaza, iki pazaryeri, sadece doğrulanan satır, belge kapsamı, aynı saniyede silme ve tekrar çalıştırma doğrulandı.\n";
