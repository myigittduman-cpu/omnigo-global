<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,NotificationSchema,DeliverySchema,OrderNotificationOrder,MobileNotificationPresentation};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Vizyona'),(2,'Diğer')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','hash','admin',NULL)");
$cipher=DeliveryCipher::withKey(str_repeat('n',32));
$detail=['events'=>['cancelled'],'buyerName'=>'Ayşe Yılmaz','address'=>'PRIVATE_ADDRESS','phone'=>'PRIVATE_PHONE'];
$db->run('INSERT INTO audit(company_id,user_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[1,1,'operations.mail',42,$cipher->seal(1,'operation-mail:1',$detail),gmdate('c')]);
$event=['id'=>9,'company_id'=>1,'company'=>'Vizyona','event_type'=>'operation','provider'=>'n11','payload'=>'{"auditId":1,"events":["cancelled"]}'];
$resolver=new OrderNotificationOrder($db,$cipher);$result=$resolver->enrich($event);
$body=MobileNotificationPresentation::create($result,'admin')['body'];
check($body==='Vizyona; Ayşe Yılmaz siparişi iptal edildi.','İptal bildirimi müşteri adını göstermeli.');
check(!str_contains(json_encode($result),'PRIVATE_'),'Bildirim yalnız adı almalı; adres/telefon taşınmamalı.');
check($resolver->enrich([...$event,'company_id'=>2])['payload']===$event['payload'],'Başka mağazanın kaydı çözümlenmemeli.');
check(!str_contains($db->one('SELECT after_value FROM audit WHERE id=1')['after_value'],'Ayşe'),'Ad şifreli kayıtta kalmalı.');
echo "İptal bildirimi: alıcı adı, şirket kapsamı ve adres/telefon yalıtımı geçti.\n";
