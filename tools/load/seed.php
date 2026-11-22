<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
[$script,$runtime,$source,$count]=$argv;
require $runtime.'/bootstrap.php';
$config=require $runtime.'/config.php';
if (!str_starts_with($config['database']['dsn'],'sqlite:'.$runtime.'/')) throw new RuntimeException('Yalnızca izole test veritabanı.');
$db=new \Omnigo\Database($config['database']); $db->pdo->exec(file_get_contents($source.'/php/tests/schema.sqlite.sql'));
\Omnigo\SupportSchema::migrate($db); \Omnigo\NotificationSchema::migrate($db); \Omnigo\DeliverySchema::install($db);
\Omnigo\FinanceSchema::install($db); \Omnigo\PushSchema::install($db); \Omnigo\ShipmentSchema::install($db); \Omnigo\RecentOrderSchema::install($db);
$cipher=new \Omnigo\DeliveryCipher($runtime.'/storage/backup.key');
$db->run("INSERT INTO companies VALUES(1,'Yük testi'),(2,'İzolasyon testi')");
$db->run("INSERT INTO users VALUES(1,'load-admin@example.test','Test Yönetici','no-login','admin',NULL),(2,'load-client@example.test','Test Müşteri','no-login','client',1)");
foreach (json_decode(file_get_contents($runtime.'/sessions.json'),true) as $session) $db->run('INSERT INTO sessions VALUES(?,?,?,?)',[hash('sha256',$session['token']),$session['role']==='admin'?1:2,$session['csrf'],time()+3600]);
$draft=['orderedAt'=>'11.10.2026 12:00','customerName'=>'Sentetik müşteri','recipientName'=>'Test alıcısı','phone'=>'05000000000','deliveryAddress'=>'Test adresi','province'=>'Ankara','district'=>'Çankaya','productName'=>'Test ürünü','invoiceCents'=>250000,'quantity'=>1,'invoiceName'=>'Test','invoiceAddress'=>'Test adresi','recipientEmail'=>'test@example.test','customerType'=>'Bireysel','packageStatus'=>''];
$db->transaction(function() use($db,$cipher,$draft,$count) {
    for ($i=1;$i<=(int)$count;$i++) {
        $db->run("INSERT INTO shipment_entries(id,company_id,city,provider,month,payload,created,updated,ordered_at) VALUES(?,1,'Ankara','n11','2026-10',?,'2026-10-11','2026-10-11','2026-10-11T12:00:00')",[$i,$cipher->seal(1,'shipment:'.$i,$draft)]);
        if ($i<=1000) {
            $order=['externalId'=>'LOAD-'.$i,'createdAt'=>'2026-10-11T12:00:00+03:00','state'=>'Aktarıldı','orders'=>1,'grossCents'=>250000,'cancelCents'=>0,'returnCents'=>0,'products'=>[['title'=>'Test ürünü','quantity'=>1]]];
            $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'order_n11',?,'2026-10-11')",[json_encode($order)]);
        }
    }
});
echo "Test verileri hazır.\n";
