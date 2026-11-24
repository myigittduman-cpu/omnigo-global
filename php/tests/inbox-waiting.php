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
foreach(['waiting','approval_pending','answered','rejected'] as $tab){
 $tabReport=$repo->summary(1,0,false,1,$tab,'n11','arama eşleşmeyecek',10,'newest',true);
 check((array)$tabReport['waitingProviderCounts']===['n11'=>1,'pazarama'=>49],'Pazaryeri rozetleri sekme, arama ve pazaryeri değişse de yanıtsız soruları sayar.');
}
$otherStore=$repo->summary(1,0,false,2,'answered','pazarama','',10,'newest',true);
check((array)$otherStore['waitingProviderCounts']===['n11'=>1,'pazarama'=>1],'Yanıtsız soru sayaçları mağaza sınırında kalır.');
$dashboard=new Omnigo\Repository($db);
$admin=['id'=>1,'role'=>'admin'];
$month=gmdate('Y-m');
check($dashboard->dashboard($admin,1,$month)['marketplaceQuestionsPending']===array_sum((array)$bucket['providerCounts']),
 'Ana menü sayacı, seçili firmanın yanıt bekleyen pazaryeri sayaçlarının toplamıdır.');
check($dashboard->dashboard($admin,2,$month)['marketplaceQuestionsPending']===2,'Ana menü sayacı firma değiştiğinde güncellenir.');
check(!isset($dashboard->dashboard(['id'=>2,'role'=>'client','company_id'=>1],1,$month)['marketplaceQuestionsPending']),
 'Yönetici soru sayacı müşteri yanıtına eklenmez.');

$replyThread=$threads[5][0];
$jobId=(new Omnigo\InboxService($db))->enqueue(1,1,'reply',['body'=>"Kuyruktaki örnek yanıt.\nİkinci satır."],$replyThread);
$findReply=static function(array $items)use($replyThread):array{
 foreach($items as $item)if($item['threadId']===$replyThread)return $item;
 return [];
};
foreach(['pending','running','verifying','unknown'] as $phase){
 $db->run('UPDATE inbox_jobs SET state=? WHERE id=?',[$phase,$jobId]);
 $result=$findReply($repo->summary(1,0,false,1,'approval_pending','pazarama','',10,'oldest',true)['items']);
 check($result['replyState']===$phase&&$result['replyBody']==="Kuyruktaki örnek yanıt.\nİkinci satır."&&!$result['canReply'],'Kuyruktaki metin firma ve soru bazında yeniden yüklenir.');
 check($repo->detail($replyThread)['replyBody']===$result['replyBody'],'Ayrıntı aynı kuyruk metnini döndürür.');
}
check(!isset($findReply($repo->summary(1,0,false,1,'approval_pending','pazarama','',50,'oldest',true)['items'])['replyBody']),'İndirme yanıt kuyruğu metnini içermez.');
check(!isset($findReply($repo->summary(1,0,false,2,'approval_pending','pazarama','',10,'oldest',true)['items'])['replyBody']),'Yanıt metni diğer firmaya taşınmaz.');
$db->run("UPDATE inbox_jobs SET state='failed' WHERE id=?",[$jobId]);
$rejected=$repo->summary(1,0,false,1,'rejected','pazarama','',10,'oldest',true);
check($rejected['rejectedCount']===1&&$findReply($rejected['items'])['replyState']==='failed','Sonuç reddedilenlere ve uyarı sayacına geçer.');
check($repo->detail($replyThread)['resolvableReplyId']===$jobId&&!$repo->detail($replyThread)['canReply'],'İletim kontrol edilene kadar yeni yanıt kilitli.');
$queue=new Omnigo\InboxService($db);
try{$queue->enqueue(1,1,'reply',['body'=>'İkinci deneme'],$replyThread);throw new RuntimeException('Doğrulanmamış gönderim tekrar sıraya alındı.');}
catch(Omnigo\HttpError $error){check($error->getCode()===409,'Tekrar gönderim sunucuda engellenir.');}
$db->run("UPDATE inbox_jobs SET state='cancelled' WHERE id=?",[$jobId]);
check($repo->detail($replyThread)['canReply'],'İletilmediği doğrulandıktan sonra yanıt yeniden yazılabilir.');
$reviews=new Omnigo\InboxRejections($db);
$reviews->review(1,1,$replyThread);
$reviews->review(1,1,$replyThread);
check($reviews->count(1,1,'pazarama')===0&&$reviews->count(2,1,'pazarama')===1,'Ret incelemesi ilgili kullanıcıya ve bu denemeye aittir.');
check($findReply($repo->summary(1,0,false,1,'rejected','pazarama','',10,'oldest',true)['items'])===[],'İncelenen ret aktif listeden kalkar.');
$db->run("UPDATE inbox_jobs SET state='sent' WHERE id=?",[$jobId]);
$sent=$findReply($repo->summary(1,0,false,1,'answered','pazarama','',10,'oldest',true)['items']);
check($sent['replyState']==='sent'&&$sent['replyBody']==="Kuyruktaki örnek yanıt.\nİkinci satır.",'Onaylanan satır mevcut gönderilmiş yanıtı gösterir.');
$approved=$threads[5][1];$approved['questionStatus']=1;$approved['replyNeeded']=false;$approved['updated']=gmdate('c');
$approved['messages'][]=['id'=>'source-seller-reply','sender'=>'SHOP_USER','body'=>'Pazaryerindeki gerçek yanıt.','created'=>gmdate('c'),'attachments'=>0];
$repo->save(1,$approved,'pazarama');
$approvedRows=$repo->summary(1,0,false,1,'answered','pazarama','',10,'oldest',true)['items'];
check($findReply($approvedRows)['replyBody']==='Pazaryerindeki gerçek yanıt.','Onaylanan kutuda pazaryeri konuşmasındaki gerçek yanıt kullanılır.');
$exportRows=$repo->summary(1,0,false,1,'answered','pazarama','',50,'oldest',true)['items'];
check(!isset($findReply($exportRows)['replyBody']),'Yanıt metni tablo dışa aktarımına eklenmez.');
$closed=[...$approved,'questionStatus'=>3];
$repo->save(1,$closed,'pazarama');
$closedRows=$repo->summary(2,0,false,1,'rejected','pazarama','',10,'oldest',true)['items'];
check(($findReply($closedRows)['replyBody']??null)==="Kuyruktaki örnek yanıt.\nİkinci satır.",'Kaldırılan kaynak sorularında yalnız mevcut gönderim metni gösterilir.');
$external=$threads[3][1];
$external['questionStatus']=3;
$external['messages'][]=['id'=>'source-rejected-reply','sender'=>'SHOP_USER','body'=>'Pazarama üzerinden verilen ve reddedilen yanıt.','created'=>'2026-11-11T13:00:00+00:00','attachments'=>0];
$externalId=$threads[3][0];
$repo->save(1,$external,'pazarama');
$repo->save(1,[...$external,'messages'=>[]],'pazarama');
$repo->save(1,[...$external,'messages'=>[[...$external['messages'][1],'body'=>'Eski yanıt','created'=>'2026-11-11T12:30:00+00:00']]],'pazarama');
$sourceRows=array_column($repo->summary(1,0,false,1,'rejected','pazarama','',10,'newest',true)['items'],null,'threadId');
$restored=$sourceRows[$externalId];
check($restored['replyBody']===$external['messages'][1]['body']&&!$restored['canReply']&&$restored['question']==='', 'Pazarama kaynak ret yanıtı geri gelir, eski veya eksik aktarım metni silmez.');
check(!$db->one('SELECT id FROM inbox_jobs WHERE thread_id=?',[$externalId])&&!$db->one('SELECT id FROM inbox_messages WHERE thread_id=?',[$externalId]), 'Geri yükleme yeni bir gönderim veya müşteri mesajı üretmez.');
$archived=array_column($repo->summary(1,0,false,1,'rejected','pazarama','',50,'newest',true)['items'],null,'threadId');
check(!isset($archived[$externalId]['replyBody']),'Kaynak yanıt metni dışa aktarıma eklenmez.');
check(!str_contains(json_encode($repo->summary(1,0,false,2,'rejected','pazarama','',10,'newest',true)), 'reddedilen yanıt'),'Kaynak yanıt metni diğer mağazaya açılmaz.');
echo "Soru filtresi: 53 soru/iki sayfa, okuma bağımsız sayaç, yanıtla azalma, zil temizliği ve firma ayrımı geçti.\n";
