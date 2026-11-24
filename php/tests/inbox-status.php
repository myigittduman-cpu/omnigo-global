<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InboxRepository,InboxStatus,InboxAttachments,DeliveryCipher,HttpError,PazaramaQuestionMapper,PazaramaInboxClient,PazaramaAuthClient,InboxFailure,InboxWorker,InboxService,IntegrationVault};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function uuid(int $id):string{return sprintf('00000000-0000-0000-0000-%012d',$id);}
function question(int $id,int $status):array{return ['questionId'=>uuid($id),'sellerId'=>uuid(1),'productName'=>'Ürün','question'=>'Ölçüsü nedir?','questionDate'=>'2026-11-01T12:30:00','questionStatus'=>$status];}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Firma A'),(2,'Firma B')");$db->run("INSERT INTO users VALUES(1,'a@test.invalid','Yönetici','hash','admin',NULL)");
$repo=new InboxRepository($db);
foreach([0=>'waiting',1=>'answered',2=>'approval_pending',3=>'rejected'] as $status=>$label){$row=PazaramaQuestionMapper::thread(question(10+$status,$status));check(InboxStatus::value($row)===$label,'Durum eşleşmesi');$repo->save(1,$row,'pazarama');check(count($repo->summary(1,0,false,1,$label)['items'])===($status===3?0:1),'SQL durum filtresi');}
check(count($repo->summary(1,0,false,null,'all')['items'])===3,'Reddedilen yeni soru kaydedilmez');
$waiting=PazaramaQuestionMapper::thread(question(10,0));$id=$repo->save(1,$waiting,'pazarama');
$repo->save(1,PazaramaQuestionMapper::thread(question(10,3)),'pazarama');
check(!$db->all('SELECT * FROM inbox_messages WHERE thread_id=?',[$id]),'Önceden alınmış soru içeriği silinmeli');
try{$repo->detail($id);throw new RuntimeException('Reddedilen soru erişilebilir');}catch(HttpError $e){check($e->getCode()===404,'Reddedilen detay gizli');}
$repo->save(1,PazaramaQuestionMapper::thread(question(10,3)),'pazarama');check(count($repo->summary(1,0,false,null,'all')['items'])===2,'Reddedilen tekrar aktarılmaz');
$key=tempnam(sys_get_temp_dir(),'inbox-key');file_put_contents($key,random_bytes(32));$folder=sys_get_temp_dir().'/inbox-test-'.bin2hex(random_bytes(8));
try{
 $vault=new IntegrationVault($db,$key);$vault->save(1,['sellerId'=>uuid(1),'apiKey'=>'fake','apiSecret'=>'fake','enabled'=>true],'pazarama');
 $token='a.'.rtrim(strtr(base64_encode(json_encode(['client_seller_id'=>uuid(1)])),'+/','-_'),'=').'.b';
 $auth=new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600])]);
 $calls=[];$raw=question(50,0);
 $client=new PazaramaInboxClient(['sellerId'=>uuid(1),'apiKey'=>'fake','apiSecret'=>'fake'],$auth,function($method,$url,$headers,$body)use(&$calls,$raw){$calls[]=[$method,$url,$body];return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>$method==='GET'?$raw:false])];});
 $mapped=$client->thread(uuid(50));$thread=$repo->save(1,$mapped,'pazarama');
 $job=(new InboxService($db))->enqueue(1,1,'reply',['body'=>'60 cm.','fingerprint'=>$mapped['fingerprint'],'recipients'=>[]],$thread,str_repeat('a',48));
 (new InboxWorker($db,$vault,fn()=>$client))->runOne();
 check($db->one('SELECT state FROM inbox_jobs WHERE id=?',[$job])['state']==='accepted','data false success true, kabul edildi ancak henüz onaylanmadı demektir');
 check(InboxStatus::value($repo->detail($thread)['data'])==='approval_pending','Sıraya alınan yanıt onay bekleyenlere geçmeli');
 check(count($repo->summary(1,0,false,1,'waiting')['items'])===0,'Gönderilen yanıt pazaryeri soru listesinden çıkmalı');
 check($calls[2][0]==='PUT'&&str_ends_with($calls[2][1],'/QuestionAnswer/sellerAnswer')&&json_decode($calls[2][2],true)===['questionId'=>uuid(50),'text'=>'60 cm.'],'Resmi yanıt sözleşmesi');
 $repo->save(1,$mapped,'pazarama');check(InboxStatus::value($repo->detail($thread)['data'])==='approval_pending','Gecikmiş API önbelleği yanıt durumunu geri almamalı');
 $repo->save(1,PazaramaQuestionMapper::thread(question(50,2)),'pazarama');check(InboxStatus::value($repo->detail($thread)['data'])==='approval_pending','Resmi onay durumu geldiğinde güncellenmeli');
 $published=PazaramaQuestionMapper::thread([...question(50,1),'answer'=>'60 cm.','answerDate'=>'2026-11-22T10:00:00']);
 $repo->save(1,$published,'pazarama');check($repo->detail($thread)['status']==='answered'&&!$repo->detail($thread)['canReply'],'Yalnız kaynak onayı Onaylanan durumuna geçirir.');
 $bad=new PazaramaInboxClient(['sellerId'=>uuid(1),'apiKey'=>'fake','apiSecret'=>'fake'],$auth,fn()=>['status'=>503,'body'=>'']);
 try{$bad->reply(uuid(50),'Deneme');throw new RuntimeException('Hata bekleniyordu');}catch(InboxFailure $e){check($e->uncertain,'Belirsiz gönderim otomatik tekrarlanmamalı');}
 $store=new InboxAttachments($folder,new DeliveryCipher($key));$pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
 $file=$store->save(1,$thread,1,'fatura.pdf',$pdf);check(!str_contains(file_get_contents($folder.'/'.$file['id'].'.bin'),'%PDF'),'Fatura diskte şifreli saklanmalı');
 check($store->load(1,$thread,1,[$file['id']])[0]['bytes']===$pdf,'Dosya içerik bütünlüğü');
 foreach([[2,$thread,1],[1,$thread+1,1],[1,$thread,2]] as $scope){try{$store->load(...[...$scope,[$file['id']]]);throw new RuntimeException('Dosya kapsamı aşıldı');}catch(HttpError $e){check($e->getCode()===404,'Dosya firma konuşma kullanıcı izolasyonu');}}
 foreach([['sahte.png',$pdf],['fatura.exe',$pdf],['buyuk.pdf',str_repeat('a',2097153)]] as $input){try{InboxAttachments::validate(...$input);throw new RuntimeException('Dosya reddedilmeli');}catch(HttpError $e){check(in_array($e->getCode(),[413,415],true),'Dosya türü/boyut sınırı');}}
 touch($folder.'/'.$file['id'].'.bin',time()-90000);$store->cleanup($db);check(!is_file($folder.'/'.$file['id'].'.bin'),'Süresi geçen dosya temizlenmeli');
 echo "Mesaj durumu, reddedilen temizliği, gerçek yanıt sözleşmesi, gönderim güvenliği ve şifreli dosya izolasyonu geçti.\n";
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);unlink($key);}
