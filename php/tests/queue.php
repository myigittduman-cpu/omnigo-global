<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,SyncQueue,ReadOnlyAdapter,SyncFailure};
function check(bool $value,string $label): void { if (!$value) throw new RuntimeException($label); }
$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$company=(new Repository($db))->createCompany('Örnek firma');
$queue=new SyncQueue($db);
$queue->enqueue($company,'n11');$queue->enqueue($company,'n11');
check((int)$db->one('SELECT COUNT(*) AS total FROM jobs')['total']===1,'Tekrarlı kuyruk kaydı');
$queue->runOne();check($db->one('SELECT state FROM jobs')['state']==='blocked','Bağlantısız pazaryeri engellenmeli');
$adapter=new class implements ReadOnlyAdapter {
 public int $calls=0;
 public function syncReadOnly(int $companyId,int $deadline): void { $this->calls++; if ($this->calls===1) throw new SyncFailure(429,120); }
};
$db->run("UPDATE jobs SET state='pending',attempts=0");
$queue=new SyncQueue($db,['n11'=>$adapter]);$now=time();$queue->runOne($now);
$row=$db->one('SELECT * FROM jobs');check($row['state']==='pending' && (int)$row['next_run']>=$now+120,'Hız sınırında bekleme');
check(!$queue->runOne($now+10),'Erken deneme yasak');
$queue->runOne((int)$row['next_run']);$row=$db->one('SELECT * FROM jobs');check((int)$row['attempts']===0 && $row['error']===null,'Başarılı iş sıfırlanmalı');
$db->run("UPDATE jobs SET state='running',lease_until=?,next_run=?,lease_token='expired'",[$now-1,$now]);
$queue->runOne($now);check($adapter->calls===3,'Yarım kalan iş kurtarılmalı');
$blocked=new class implements ReadOnlyAdapter { public function syncReadOnly(int $companyId,int $deadline): void { throw new SyncFailure(401); } };
$db->run('UPDATE jobs SET next_run=?',[$now]);(new SyncQueue($db,['n11'=>$blocked]))->runOne($now);
check($db->one('SELECT state FROM jobs')['state']==='blocked','Yetki hatası otomatik döngüye girmemeli');

$rateLimited=new class implements ReadOnlyAdapter { public function syncReadOnly(int $companyId,int $deadline): void { throw new SyncFailure(429,7200); } };
$db->run("UPDATE jobs SET state='pending',attempts=4,next_run=?",[$now]);
(new SyncQueue($db,['n11'=>$rateLimited]))->runOne($now);
$row=$db->one('SELECT * FROM jobs');
check($row['state']==='pending' && (int)$row['next_run']>=$now+7200,'Uzun Retry-After korunmalı; hız sınırı başarısız iş sayılmamalı');

foreach([408,425,500,502,503,504,522,524] as $status){
 $outage=new class($status) implements ReadOnlyAdapter {
  public int $calls=0; public bool $available=false;
  public function __construct(private int $status){}
  public function syncReadOnly(int $companyId,int $deadline):void{$this->calls++;if(!$this->available)throw new SyncFailure($this->status);}
 };
 $db->run("UPDATE jobs SET state='pending',attempts=4,next_run=?",[$now]);
 $retryQueue=new SyncQueue($db,['n11'=>$outage]);$retryQueue->runOne($now);$retry=$db->one('SELECT * FROM jobs');
 check($retry['state']==='pending'&&(int)$retry['attempts']===5&&(int)$retry['next_run']>=$now+300,'Geçici hata beşinci denemede kalıcı durmamalı: '.$status);
 check(!$retryQueue->runOne($now+60)&&$outage->calls===1,'Kesintide sık istek yasak: '.$status);
 $outage->available=true;$retryQueue->runOne((int)$retry['next_run']);$recovered=$db->one('SELECT * FROM jobs');
 check($recovered['state']==='pending'&&(int)$recovered['attempts']===0&&$recovered['error']===null,'Servis düzeldiğinde aktarım kendiliğinden toparlanmalı: '.$status);
}
foreach([401=>'blocked',403=>'blocked',422=>'failed',0=>'failed'] as $status=>$state){
 $failure=new class($status) implements ReadOnlyAdapter{public function __construct(private int $status){}public function syncReadOnly(int $companyId,int $deadline):void{if(!$this->status)throw new LogicException('Yerel doğrulama');throw new SyncFailure($this->status);}};
 $db->run("UPDATE jobs SET state='pending',attempts=4,next_run=?",[$now]);(new SyncQueue($db,['n11'=>$failure]))->runOne($now);
 check($db->one('SELECT state FROM jobs')['state']===$state,'Kalıcı veri/yetki hataları kör döngüye alınmamalı: '.$status);
}
$db->run("UPDATE jobs SET state='pending',attempts=99,next_run=?",[$now]);
(new SyncQueue($db,['n11'=>new class implements ReadOnlyAdapter{public function syncReadOnly(int $companyId,int $deadline):void{throw new SyncFailure(522);}}]))->runOne($now);
$row=$db->one('SELECT * FROM jobs');check($row['state']==='pending'&&(int)$row['next_run']===$now+300,'Uzun kesintide geri çekilme beş dakikada sınırlanır');
echo "Kuyruk: tekrarsız kayıt, hız sınırı, bekleme, yeniden deneme, yarım kalan iş ve yetki engeli geçti.\n";

foreach(['failed','blocked'] as $previous){
 $message=$previous==='blocked'?'Pazaryeri yetkisi doğrulanamadı.':'Veri alınamadı; yeniden deneme sınırına ulaşıldı.';
 $db->run('UPDATE jobs SET state=?,error=?,next_run=?,attempts=5,lease_token=NULL',[$previous,$message,$now+1800]);
 $reader=new class implements ReadOnlyAdapter{public int $calls=0;public function syncReadOnly(int $companyId,int $deadline):void{$this->calls++;}};
 $recovery=new SyncQueue($db,['n11'=>$reader]);check(!$recovery->runOne($now+1799),'İzinli bekleme süresi dolmadan kaynak tekrar çağrılmaz');
 check($recovery->runOne($now+1800)&&$reader->calls===1,'Kaynak/yetki düzeldiğinde kayıt yeniden kaydedilmeden aktarım devam eder');
 check($db->one('SELECT state,attempts,error FROM jobs')===['state'=>'pending','attempts'=>0,'error'=>null],'Başarılı toparlanma eski hatayı temizler');
}
echo "Kuyruk: uzun kesinti, kaynak veri düzeltmesi ve yetki geri geldiğinde kontrollü otomatik devam geçti.\n";
