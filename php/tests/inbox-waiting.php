<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Omnigo\{Database,InboxRepository,NotificationFeed,NotificationSchema,SupportSchema};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));NotificationSchema::migrate($db);Omnigo\DeliverySchema::install($db);Omnigo\ShipmentSchema::install($db);SupportSchema::migrate($db);
$db->run("INSERT INTO companies VALUES(1,'Firma A'),(2,'Firma B')");$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Yönetici','hash','admin',NULL)");
$repo=new InboxRepository($db);$feed=new NotificationFeed($db);$threads=[];
for($i=1;$i<=53;$i++){
 $thread=['id'=>'question-'.$i,'title'=>'Ürün','updated'=>'2026-11-11T12:00:00Z','questionStatus'=>0,'replyNeeded'=>true,'recipients'=>[],'entities'=>[],'fingerprint'=>str_repeat('a',64),'partial'=>false,'messages'=>[['id'=>'message-'.$i,'sender'=>'CUSTOMER_USER','body'=>'Ölçüsü nedir?','created'=>'2026-11-11T12:00:00Z','attachments'=>0]]];
 $threads[$i]=[$repo->save(1,$thread,'pazarama'),$thread];
}
$repo->save(2,$thread,'pazarama');$thread=$threads[1][1];$thread['messages'][]=[...$thread['messages'][0],'id'=>'follow-up'];$repo->save(1,$thread,'pazarama');
check($repo->summary(1,0,false,1)['waitingCount']===53&&count($repo->summary(1,0,false,1)['items'])===50&&count($repo->summary(1,1,false,1)['items'])===3,'Sayaç tüm sayfalardaki tekil soruları sayar');
$message=$repo->summary(1,0,false,1)['items'][0]['id'];$repo->markRead(1,$message,1);check($repo->summary(1,0,true,1)['waitingCount']===53,'Okunmuş bekleyen soru sayaçta kalır');
foreach([1=>1,2=>2,3=>3] as $i=>$status){$row=$threads[$i][1];$row['questionStatus']=$status;$row['replyNeeded']=false;$repo->save(1,$row,'pazarama');}
$local=$threads[4][1];$local['localReplyState']='sent';$local['replyNeeded']=false;$repo->save(1,$local,'pazarama');
check($repo->summary(1,0,false,1)['waitingCount']===49&&count($repo->summary(1,0,false,1)['items'])===49,'Yanıt/onay/red bekleyen sayaçtan çıkar');
check(count($repo->summary(1,0,false,1,'answered')['items'])===2&&count($repo->summary(1,0,false,1,'approval_pending')['items'])===1,'Yanıtlananlar sadece cevaplandı, onay ayrı');
$notifications=[...$feed->listing(1,1,0)['items'],...$feed->listing(1,1,1)['items']];check(count($notifications)===0,'Soru bölümü kaldırıldığında hiçbir soru bildirimi zilde görünmez');
foreach($notifications as $event)check(!in_array($event['targetId'],array_column(array_slice($threads,0,4),0),true),'Zilde yalnızca yanıt bekleyenler');
check($repo->summary(1,0,false,2)['waitingCount']===1,'Sayaç firma bazında');
$n11=$threads[5][1];$n11['questionIdentifiers']=['sku'=>'QA-MODEL'];
$repo->save(1,$n11,'n11');
$repo->save(2,$n11,'n11');
$bucket=$repo->summary(1,2,false,1,'waiting','pazarama','',10,'newest',true);
check($bucket['total']===49&&(array)$bucket['providerCounts']==['n11'=>1,'pazarama'=>49],
 'Pazaryeri sayaçları görünür sayfadan bağımsız, firma ve durumla sınırlı.');
$search=$repo->summary(1,0,false,1,'waiting','pazarama','QA-MODEL',10,'newest',true);
check($search['total']===0&&(array)$search['providerCounts']===['n11'=>1],
 'Pazaryeri sayıları listeyle aynı arama filtresini kullanır.');
$rejected=$repo->summary(1,0,false,1,'rejected','n11','',10,'newest',true);
check($rejected['total']===0&&(array)$rejected['providerCounts']===['pazarama'=>1],
 'Reddedilen sekmesinde her pazaryerinin yalnız reddedilen soruları sayılır.');
$dashboard=new Omnigo\Repository($db);
$admin=['id'=>1,'role'=>'admin'];
$month=gmdate('Y-m');
check($dashboard->dashboard($admin,1,$month)['marketplaceQuestionsPending']===array_sum((array)$bucket['providerCounts']),
 'Ana menü sayacı, seçili firmanın yanıt bekleyen pazaryeri sayaçlarının toplamıdır.');
check($dashboard->dashboard($admin,2,$month)['marketplaceQuestionsPending']===2,'Ana menü sayacı firma değiştiğinde güncellenir.');
check(!isset($dashboard->dashboard(['id'=>2,'role'=>'client','company_id'=>1],1,$month)['marketplaceQuestionsPending']),
 'Yönetici soru sayacı müşteri yanıtına eklenmez.');
echo "Soru filtresi: 53 soru/iki sayfa, okuma bağımsız sayaç, yanıtla azalma, zil temizliği ve firma ayrımı geçti.\n";
