<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InboxMapping,InboxRepository,InboxService,InboxWorker,IntegrationVault,InboxFailure,BackupArchive};
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function fresh(): Database {$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));return $db;}
function sample(string $id='thread-1',string $message='message-1',string $sender='CUSTOMER_USER'): array {
 return ['id'=>$id,'date_updated'=>'2026-11-08T12:00:00Z','topic'=>['value'=>'Ürün hakkında <script>örnek</script>'],'current_participants'=>[['type'=>'CUSTOMER','id'=>'buyer-1']],'authorized_participants'=>[['type'=>'CUSTOMER','id'=>'buyer-1']],'entities'=>[['id'=>'order-1','type'=>'MMP_ORDER','label'=>'Ürün']],'metadata'=>['last_message_date'=>'2026-11-08T12:00:00Z','total_count'=>1,'shop_reply_needed_since'=>'2026-11-08T12:00:00Z'],'messages'=>[['id'=>$message,'body'=>'Ölçüsü nedir?','date_created'=>'2026-11-08T12:00:00Z','from'=>['type'=>$sender,'display_name'=>'Gereksiz kişisel alan'],'attachments'=>[]]]];
}
$db=fresh();$db->run("INSERT INTO companies VALUES(1,'Firma Bir')");$db->run("INSERT INTO companies VALUES(2,'Firma İki')");
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Birinci yönetici','hash','admin',NULL)");$db->run("INSERT INTO users VALUES(2,'admin2@example.test','İkinci yönetici','hash','admin',NULL)");
$repo=new InboxRepository($db);$raw=sample();$mapped=InboxMapping::thread($raw);$thread=$repo->save(1,$mapped);$repo->save(1,$mapped);
check((int)$db->one('SELECT COUNT(*) AS n FROM inbox_messages')['n']===1,'Aynı mesaj ikinci bildirim oluşturmamalı');
check(!str_contains(json_encode($repo->detail($thread)),'Gereksiz kişisel alan'),'Gereksiz kişisel alanlar saklanmamalı');
$message=(int)$repo->summary(1)['items'][0]['id'];$repo->markRead(1,$message);$repo->markRead(1,$message);
check($repo->summary(1)['unreadCount']===0&&$repo->summary(2)['unreadCount']===1,'Okunma durumu yöneticiye özel olmalı');
$other=$repo->save(2,$mapped);check($other!==$thread&&$repo->summary(1)['unreadCount']===1,'Aynı kaynak kimliği firmalar arasında karışmamalı');
check($repo->summary(1,0,false,1)['unreadCount']===0&&$repo->summary(1,0,false,2)['unreadCount']===1,'Okunmamış sayısı seçili firmaya ait olmalı');
check(count($repo->summary(1,0,false,1)['items'])===1&&$repo->summary(1,0,false,1)['items'][0]['companyId']===1,'Liste seçili firmaya ait olmalı');
$operator=$raw;$operator['current_participants']=[['type'=>'OPERATOR']];check(InboxMapping::thread($operator)===null,'Operatör konuşması müşteri sorusu değil');
$outgoing=sample('thread-1','message-shop','SHOP_USER');$repo->save(1,InboxMapping::thread($outgoing));check($repo->summary(1)['unreadCount']===1,'Mağaza mesajı yeni müşteri bildirimi değil');
$partial=$raw;$partial['metadata']['total_count']=101;check(InboxMapping::thread($partial)['partial'],'Eksik konuşma işaretlenmeli');
check(count($repo->summary(1,0,true)['items'])===1,'Okunmamış filtresi doğru olmalı');
$key=tempnam(sys_get_temp_dir(),'omnigo-inbox-key-');file_put_contents($key,random_bytes(32));$vault=new IntegrationVault($db,$key);
$vault->save(1,['apiKey'=>'00000000-0000-0000-0000-000000000000','shopId'=>10673,'enabled'=>true]);
$fake=new class {
 public array $raw;public int $reads=0;public int $sends=0;public bool $rateLimit=false;public bool $uncertain=false;public ?string $cursor=null;
 public function threads(?string $cursor=null):array{$this->reads++;if($this->rateLimit)throw new InboxFailure(429,7200);return ['data'=>[$this->raw]];}
 public function thread(string $id):array{$this->reads++;return $this->raw;}
 public function reply(string $id,string $body,array $to):array{$this->sends++;if($this->uncertain)throw new InboxFailure(503,0,true);return ['thread_id'=>$id,'message_id'=>'sent-1'];}
};$fake->raw=$raw;
$queue=new InboxWorker($db,$vault,fn()=>$fake);$service=new InboxService($db);$now=time();
$job=$service->enqueue(1,1,'scan');check($service->enqueue(1,1,'scan')===$job,'Çakışan kontrol işini çoğaltma');$queue->runOne();
check(count($repo->summary(1,0,false,1)['jobs'])===1&&count($repo->summary(1,0,false,2)['jobs'])===0,'Son işlemler seçili firmaya ait olmalı');
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$job])['state']==='done','Kontrol tamamlanmalı');
check(!$queue->runOne(time()+99999),'Kontrol kendi kendine tekrar planlanmamalı');
$payload=['body'=>'Ürün ölçüsü 60 cm.','fingerprint'=>$mapped['fingerprint'],'recipients'=>$mapped['recipients']];
$reply=$service->enqueue(1,1,'reply',$payload,$thread,str_repeat('a',48));$queue->runOne();
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$reply])['state']==='sent'&&$fake->sends===1,'Onaylı gönderim kaydedilmeli');
check($service->enqueue(1,1,'reply',$payload,$thread,str_repeat('a',48))===$reply,'Yanıt isteği tekrarı aynı kimliği döndürmeli');check(!$queue->runOne(),'Aynı yanıt tekrar gönderilmemeli');
$fake->raw=sample('thread-1','new-message');$reply=$service->enqueue(1,1,'reply',$payload,$thread,str_repeat('b',48));$queue->runOne();
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$reply])['state']==='failed'&&$fake->sends===1,'Yeni mesaj varsa eski taslak gönderilmemeli');
$db->run("UPDATE inbox_jobs SET state='cancelled' WHERE id=?",[$reply]);
$fake->raw=$raw;$fake->uncertain=true;$reply=$service->enqueue(1,1,'reply',$payload,$thread,str_repeat('c',48));$queue->runOne();
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$reply])['state']==='unknown','Belirsiz gönderim açıkça işaretlenmeli');$calls=$fake->sends;$queue->runOne(time()+99999);check($fake->sends===$calls,'Belirsiz yanıt otomatik yeniden gönderilemez');
$db->run("UPDATE inbox_jobs SET state='running',lease_until=?,lease_token='expired' WHERE id=?",[time()-1,$reply]);$queue->runOne();check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$reply])['state']==='unknown','Yarım kalan gönderim tekrarlanmamalı');
$db->run("UPDATE inbox_jobs SET created='2020-01-01' WHERE kind='scan'");$fake->rateLimit=true;$scan=$service->enqueue(1,1,'scan');$queue->runOne();$wait=$db->one('SELECT state,next_run FROM inbox_jobs WHERE id=?',[$scan]);check($wait['state']==='pending'&&(int)$wait['next_run']>=$now+7200,'Retry-After korunmalı');
// Pagination resumes the requested scan without importing duplicate notifications.
$pagingClient=new class {public int $calls=0;public array $cursors=[];public function threads(?string $cursor=null):array{$this->cursors[]=$cursor;$this->calls++;return $cursor===null?['data'=>[sample('paged-thread','paged-message')],'next_page_token'=>'opaque-page-2']:['data'=>[sample('paged-thread','paged-message'),sample('last-thread','last-message')]];}};
$paging=new InboxWorker($db,$vault,fn()=>$pagingClient);$db->run("UPDATE inbox_jobs SET next_run=?,attempts=0 WHERE id=?",[time(),$scan]);$paging->runOne();$paging->runOne(time()+2);
check($pagingClient->cursors===[null,'opaque-page-2'],'Sayfalama tokenı sonraki isteğe aktarılmalı');
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$scan])['state']==='done','Son sayfadan sonra kontrol tamamlanmalı');
check((int)$db->one("SELECT COUNT(*) AS n FROM inbox_messages WHERE remote_id='paged-message'")['n']===1,'Sayfalardaki aynı bildirim çoğaltılmamalı');
$outageClient=new class {public int $failures=6;public array $cursors=[];public function threads(?string $cursor=null):array{$this->cursors[]=$cursor;if($this->failures-->0)throw new InboxFailure(503);return ['data'=>[sample('last-thread','last-message')]];}};
$recovering=new InboxWorker($db,$vault,fn()=>$outageClient);
$db->run("UPDATE inbox_jobs SET state='pending',attempts=0,next_run=?,payload=? WHERE id=?",[time(),json_encode(['cursor'=>'opaque-page-2','updatedSince'=>Omnigo\InboxQuestionWindow::START]),$scan]);
for($attempt=0;$attempt<6;$attempt++){
 $next=(int)$db->one('SELECT next_run FROM inbox_jobs WHERE id=?',[$scan])['next_run'];$recovering->runOne($next);
 $retry=$db->one('SELECT state,next_run FROM inbox_jobs WHERE id=?',[$scan]);
 check($retry['state']==='pending'&&(int)$retry['next_run']<=$next+310,'Uzayan okuma kesintisi işi durdurmamalı; beş dakikalık yeniden deneme korunmalı');
}
$recovering->runOne((int)$retry['next_run']);
check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$scan])['state']==='done','Bağlantı düzelince yarım kalan aktarım tamamlanmalı');
check($outageClient->cursors===array_fill(0,7,'opaque-page-2'),'Kesinti boyunca sayfalama kaldığı yerden sürmeli');
check((int)$db->one("SELECT COUNT(*) AS n FROM inbox_messages WHERE remote_id='last-message'")['n']===1,'Kesinti sonrası aynı mesaj çoğaltılmamalı');
$db->run("UPDATE inbox_jobs SET state='pending' WHERE id=?",[$scan]);
// New data participates in encrypted archives; restored jobs must never send messages.
$archive=BackupArchive::create($db,str_repeat('x',32));$restored=fresh();BackupArchive::restore($restored,$archive,str_repeat('x',32));
check(count($restored->all('SELECT * FROM inbox_messages'))===count($db->all('SELECT * FROM inbox_messages')),'Mesajlar yedekten geri gelmeli');
check($restored->one('SELECT state FROM inbox_jobs WHERE id=?',[$reply])['state']==='unknown','Geri yükleme mesaj göndermemeli');check($restored->one('SELECT state FROM inbox_jobs WHERE id=?',[$scan])['state']==='failed','Geri yükleme kontrol işini otomatik başlatmamalı');
$legacy=new Database(['dsn'=>'sqlite::memory:']);$legacySql=explode('CREATE TABLE IF NOT EXISTS inbox_threads',file_get_contents(__DIR__.'/schema.sqlite.sql'))[0];$legacy->pdo->exec($legacySql);$oldArchive=BackupArchive::create($legacy,str_repeat('x',32));BackupArchive::restore(fresh(),$oldArchive,str_repeat('x',32));
unlink($key);echo "Mesaj testleri geçti: firma ayrımı, okunmamış bildirim, tekrar engeli, eski taslak, belirsiz gönderim, kuyruk kurtarma, hız sınırı ve eski/yeni yedek uyumu.\n";
