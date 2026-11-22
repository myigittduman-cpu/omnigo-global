<?php
declare(strict_types=1);

use Omnigo\{CustomerInitialPolicy,CustomerNotificationPolicy,Database,NotificationResumePolicy,NotificationSchema};

require dirname(__DIR__).'/bootstrap.php';
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
NotificationSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Deneme'),(2,'Kontrol')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Yönetici','x','admin',NULL),(2,'store@example.test','Mağaza','x','client',1)");
$admin=['id'=>1,'role'=>'admin'];$client=['id'=>2,'role'=>'client','company_id'=>1];
$profile=['workspacePolicy'=>CustomerInitialPolicy::template($db)];
$profiles=static function(array $value)use($db):void{
    $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(1,?) ON CONFLICT(company_id) DO UPDATE SET payload=excluded.payload',
        [json_encode($value,JSON_THROW_ON_ERROR)]);
};
$profiles($profile);
$assert=static function(bool $truth,string $message):void{if(!$truth)throw new RuntimeException($message);};
$event=static function(array $events,int $company=1)use($db):int{
    $db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,'portal',?,'operation',?,?)",
        [$company,hash('sha256',json_encode([$events,$company,random_int(0,PHP_INT_MAX)])),
            json_encode(['events'=>$events],JSON_THROW_ON_ERROR),gmdate('c')]);
    return $db->insertId();
};
$select=static function(CustomerNotificationPolicy $policy)use($db):array{
    return array_column($db->all('SELECT id FROM order_notifications WHERE company_id=1 AND '.$policy->predicate('event_type','payload').' ORDER BY id'),'id');
};
$row=static fn(int $id):array=>$db->one('SELECT * FROM order_notifications WHERE id=?',[$id]);
$depleted=$event(['inventory_low']);
$adjusted=$event(['inventory_adjusted']);
$price=$event(['purchase_price_updated']);
$request=$event(['cancellation_requested']);
$cancelled=$event(['cancelled']);
$mixed=$event(['cancellation_requested','cancelled']);
$other=$event(['inventory_low'],2);
$normal=new CustomerNotificationPolicy($db,$admin,1);
$assert($select($normal)===[$depleted,$adjusted,$price,$request,$cancelled,$mixed],'Eski profiller tüm açık bildirimleri korur.');
$changed=$profile;
$changed['workspacePolicy']['notifications']['stockAdjusted']=false;
$changed['workspacePolicy']['notifications']['orderCancellationRequests']=false;
$profiles($changed);
$policy=new CustomerNotificationPolicy($db,$admin,1);
$assert($select($policy)===[$depleted,$price,$cancelled,$mixed],'Alt anahtarlar sorguda bağımsız uygulanır.');
$assert(!$policy->permits($row($adjusted))&&!$policy->permits($row($request))&&$policy->permits($row($mixed)),'Düğme, sorgu ve cihaz filtresi aynı olayları seçer.');
$assert(!$policy->permits($row($other))&&$select(new CustomerNotificationPolicy($db,$client,1))===[],'Mağaza ve alıcı rolü kapsamı korunur.');
$mail=static fn(array $events):array=>['type'=>'operation','detail'=>['companyId'=>1,'events'=>$events]];
$assert(!NotificationResumePolicy::mailAllowed($db,1,'operation-10',$mail(['cancellation_requested']))
    &&NotificationResumePolicy::mailAllowed($db,1,'operation-10',$mail(['cancelled'])),'İptal e-posta ayrımı aynı tercihi kullanır.');
$changed['workspacePolicy']['notifications']['stock']=false;
$profiles($changed);
$assert($select(new CustomerNotificationPolicy($db,$admin,1))===[$cancelled,$mixed],'Stok grubu alt tercihleri bastırır.');
$previous=$changed;
$changed['workspacePolicy']['notifications']['stock']=true;
$changed['workspacePolicy']['notifications']['stockAdjusted']=true;
$changed['notificationResume']=NotificationResumePolicy::update($db,1,$previous,$changed);
$profiles($changed);
$fresh=$event(['inventory_adjusted']);
$resume=new CustomerNotificationPolicy($db,$admin,1);
$assert($select($resume)===[$cancelled,$mixed,$fresh]&&!$resume->permits($row($adjusted))&&$resume->permits($row($fresh)),
    'Yeniden açılan stok grubu geçmiş kayıtları teslim etmez ve yeni kaydı geçirir.');
echo "Bildirim grupları, alt olaylar, eski izinler, mağaza ayrımı ve teslim sınırı doğrulandı.\n";
