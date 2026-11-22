<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
[$script,$runtime,$source]=$argv;
$runtime=str_replace('\\','/',$runtime);
require $runtime.'/bootstrap.php';
$config=require $runtime.'/config.php';
if($config['database']['dsn']!=='sqlite:'.$runtime.'/review.sqlite')throw new RuntimeException('Yalnızca yerel inceleme veritabanı kullanılabilir.');
date_default_timezone_set('Europe/Istanbul');
$db=new \Omnigo\Database($config['database']);
$db->pdo->exec(file_get_contents($source.'/php/tests/schema.sqlite.sql'));
foreach(['SupportSchema'=>'migrate','NotificationSchema'=>'migrate','DeliverySchema'=>'install','FinanceSchema'=>'install','PushSchema'=>'install','ShipmentSchema'=>'install','RecentOrderSchema'=>'install'] as $class=>$method){$name='Omnigo\\'.$class;$name::$method($db);}
$repository=new \Omnigo\Repository($db);
$company=$repository->createCompany('Örnek Ev & Yaşam');
$other=$repository->createCompany('Örnek İkinci İşletme');
$hash=\Omnigo\Security::hashPassword('Omnigo-Demo-2026!');
foreach([['yonetici@ornek.test','Örnek Yönetici','admin',null],['musteri@ornek.test','Örnek Müşteri','client',$company],['diger@ornek.test','Diğer Müşteri','client',$other]] as [$email,$name,$role,$id])$db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)',[$email,$name,$hash,$role,$id]);
$admin=['id'=>1,'role'=>'admin'];$month=date('Y-m');
foreach($db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='channels'",[$company]) as $record){$value=json_decode($record['payload'],true);$value['storeName']='Örnek Ev & Yaşam';$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($value,JSON_UNESCAPED_UNICODE),$record['id']]);}
foreach([['n11',18645000,42],['Pazarama',9280000,23],['Koçtaş',13860000,31]] as [$channel,$amount,$count])$repository->save($admin,$company,'sales',['channel'=>$channel,'month'=>$month,'grossCents'=>$amount,'cancelCents'=>0,'returnCents'=>0,'orders'=>$count,'source'=>'Sentetik mağaza tanıtım verisi'],null);
// Synthetic fixtures only, protected above by the exact local SQLite path guard.
foreach([['n11',3540000,2],['Pazarama',1820000,4]] as [$channel,$amount,$days])$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,json_encode(['channel'=>$channel,'expectedDate'=>date('Y-m-d',strtotime('+'.$days.' days')),'expectedCents'=>$amount,'status'=>'pending','source'=>'Sentetik örnek ödeme planı'],JSON_UNESCAPED_UNICODE),date(DATE_ATOM)]);
foreach([['Ürün içeriklerinin kontrolü','Ürün başlıkları ve açıklamalar birlikte gözden geçiriliyor.','running'],['Aylık performans değerlendirmesi','Satış özeti ve sonraki dönem çalışmaları hazırlanıyor.','planned']] as [$title,$description,$status])$repository->save($admin,$company,'tasks',['title'=>$title,'description'=>$description,'channel'=>'Tüm kanallar','date'=>date('Y-m-d'),'status'=>$status,'visibility'=>'client','owner'=>'Omnigo operasyon ekibi'],null);
$cipher=new \Omnigo\DeliveryCipher($runtime.'/storage/backup.key');
$deliveries=new \Omnigo\DeliveryRepository($db,$cipher);
\Omnigo\ShipmentOrderIngestor::activate($db,$company,time()-40*86400);
foreach([['Ankara','Çankaya','Deniz Örnek','Çamaşır Makinesi',1850000,'Created',1],['Eskişehir','Odunpazarı','Ece Örnek','Bulaşık Makinesi',2460000,'Shipped',3],['Kayseri','Melikgazi','Can Örnek','Buzdolabı',3290000,'Created',7],['Ankara','Yenimahalle','Ada Örnek','Kurutma Makinesi',2190000,'Created',2]] as $i=>[$city,$district,$buyer,$title,$cents,$status,$days]){
 $id='ORNEK-'.(1001+$i);$at=new DateTimeImmutable('-'.$days.' days');
 $raw=['id'=>(string)(1001+$i),'orderNumber'=>$id,'customerfullName'=>$buyer,'customerEmail'=>'alici'.($i+1).'@example.invalid','shippingAddress'=>['fullName'=>$buyer,'gsm'=>'05000000000','city'=>$city,'district'=>$district,'address'=>'Örnek Mahallesi, Tanıtım Sokak No: 1','postalCode'=>'00000'],'billingAddress'=>['fullName'=>$buyer,'address'=>'Örnek Mahallesi, Tanıtım Sokak No: 1','city'=>$city,'district'=>$district,'invoiceType'=>1],'packageHistories'=>[['status'=>'Created','createdDate'=>$at->getTimestamp()*1000]],'shipmentPackageStatus'=>$status,'lines'=>[['orderLineId'=>$id,'orderItemLineItemStatusName'=>$status,'productName'=>$title,'stockCode'=>'ORNEK-URUN-'.($i+1),'quantity'=>1,'sellerInvoiceAmount'=>number_format($cents/100,2,'.','')]]];
 $delivery=\Omnigo\DeliveryMapper::n11($raw);$delivery['details']=\Omnigo\OrderDetailMapper::n11($raw,$delivery);$delivery['shipmentItems']=\Omnigo\ShipmentOrderMapper::n11($raw,$delivery);
 $db->transaction(fn()=>$deliveries->ingest($company,$delivery));
 $order=['externalId'=>$id,'createdAt'=>$at->format(DATE_ATOM),'state'=>$status==='Shipped'?'Kargolandı':'Yeni sipariş','orders'=>1,'grossCents'=>$cents,'cancelCents'=>0,'returnCents'=>0,'products'=>[['title'=>$title,'quantity'=>1]]];
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'order_n11',?,?)",[$company,json_encode($order,JSON_UNESCAPED_UNICODE),date(DATE_ATOM)]);
}
// No jobs or provider credentials are installed; all data is synthetic and local.
$db->run('DELETE FROM mail_outbox');
echo "İki rol ve iki şirket için sentetik yerel inceleme ortamı hazır.\n";
