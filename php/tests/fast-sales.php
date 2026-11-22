<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,FinancialRefreshBatch,ScheduledReadAdapter,FixedCadenceReadAdapter,SyncQueue,SyncFailure};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Test')");
$db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_idefix','{}','')");
$reader=new class($db) implements ScheduledReadAdapter {
    public int $calls=0;public int $remaining=3;public bool $limited=false;
    public function __construct(private Database $db){}
    public function nextDelay():int{return 900;}
    public function syncReadOnly(int $company,int $deadline):void{
        $this->calls++;if($this->limited)throw new SyncFailure(429,180);
        $state=--$this->remaining>0?['scan'=>['page'=>$this->calls]]:['lastSuccessAt'=>gmdate('c')];
        $this->db->run("UPDATE records SET payload=? WHERE kind='sync_idefix'",[json_encode($state)]);
    }
};
$now=time();$paused=0;$batch=new FinancialRefreshBatch($db,'idefix',$reader,function(int $seconds)use(&$paused){$paused+=$seconds;},fn()=>$now);
$batch->syncReadOnly(1,$now+50);check($reader->calls===3&&$paused===2,'Tam tarama tek işte tamamlanır, tamamlanınca yeniden başlamaz');
check($batch->nextDelay()===60,'Sağlıklı mali tarama bir dakika sonra');
$reader->remaining=11;$before=$reader->calls;$batch->syncReadOnly(1,$now+50);check($reader->calls-$before===8&&$reader->remaining===3,'Büyük geçmiş tek işçiyi sınırsız meşgul etmez');
$batch->syncReadOnly(1,$now+50);check($reader->remaining===0,'Kalıcı imleç kalan sayfaları tamamlar');
$reader->remaining=8;$before=$reader->calls;
$timed=new FinancialRefreshBatch($db,'idefix',$reader,function(int $seconds)use(&$now){$now+=$seconds+3;},function()use(&$now){return $now;});
$timed->syncReadOnly(1,$now+10);check($reader->calls===$before+1,'İşçi son tarihinden önce durur');
$reader->limited=true;$before=$reader->calls;
try{$batch->syncReadOnly(1,$now+50);throw new RuntimeException('429 yutuldu');}catch(SyncFailure $error){check($error->retryAfter===180&&$reader->calls===$before+1,'429 sonrası ilave çağrı yok, Retry-After korunur');}
$old=['lastSuccessAt'=>gmdate('c',$now-120),'recentOrders'=>['historyNextAt'=>$now+780]];
check($batch->dueAt($old)===$now-60,'Eski sağlıklı 15 dakikalık zamanlayıcı otomatik kısalır');
foreach([['failure'=>['code'=>503]],['phase'=>'error'],['recentOrders'=>['historyNextAt'=>$now+780,'historyBackoff'=>true]]] as $failure){check($batch->dueAt(array_replace($old,$failure))===$now+780,'Gerçek hata beklemesi kısaltılmaz');}
$fixed=new class implements FixedCadenceReadAdapter {public function nextDelay():int{return 60;}public function syncReadOnly(int $company,int $deadline):void{sleep(2);}};
$db->run("INSERT INTO jobs(company_id,provider,next_run) VALUES(1,'n11',0)");$started=time();
(new SyncQueue($db,['n11'=>$fixed]))->runOne($started,['n11']);$job=$db->one("SELECT * FROM jobs WHERE provider='n11'");
check((int)$job['next_run']===$started+60&&$job['state']==='pending'&&(int)$job['attempts']===0,'Başarılı hızlı işin çalışma süresi her turda takvime eklenmez');
$normal=new class implements ScheduledReadAdapter {public function nextDelay():int{return 900;}public function syncReadOnly(int $company,int $deadline):void{}};
$db->run("UPDATE jobs SET next_run=0 WHERE provider='n11'");$started=time();(new SyncQueue($db,['n11'=>$normal]))->runOne($started,['n11']);
check((int)$db->one("SELECT next_run FROM jobs WHERE provider='n11'")['next_run']>=$started+900,'Diğer işlerin takvimi korunur');
echo "Hızlı finans: tamamlanan tarama, sayfa/zaman sınırı, devam imleci, hız sınırı, eski zamanlayıcı, hata beklemesi ve sabit aralık testleri geçti.\n";
