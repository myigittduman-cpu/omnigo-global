<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliveryCipher,DeliveryRepository,FinanceSchema,FinanceStore,InboxRepository,IntegrationVault,NewPayoutRecords,NewQuestionRecords,NewReturnRecords,UpcomingPayoutAdapter,UpcomingPayoutStore};

function check(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
function question(string $id,int $time,int $status=0):array {
    $stamp=gmdate('c',$time);
    return ['id'=>$id,'questionStatus'=>$status,'updated'=>$stamp,'replyNeeded'=>$status===0,
        'messages'=>[['id'=>'msg-'.$id,'sender'=>'CUSTOMER_USER','created'=>$stamp,'body'=>'Ürün sorusu']]];
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
FinanceSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");
$keyPath=tempnam(sys_get_temp_dir(),'table-reader');
file_put_contents($keyPath,random_bytes(32));
try {
    $now=time();
    $requested=$now-20;
    $vault=new IntegrationVault($db,$keyPath);
    $settings=['enabled'=>true,'startDate'=>gmdate('Y-m-d',$now-2*86400),'connectedAt'=>$now-86400,'revision'=>'demo'];
    $vault->save(1,$settings,'n11');
    $scope=static fn(string $table,string $mode):array=>['through'=>$now,'target'=>compact('table','mode')+['requestedAt'=>$requested,'after'=>0]];
    $inbox=new InboxRepository($db);
    $closed=question('already-answered',$now-100,1);
    $inbox->save(1,$closed,'n11');
    $threads=[question('open',$now-60),question('source-answered',$now-80,1),question('already-answered',$now-60),question('old',$now-6*86400)];
    $questionReader=new NewQuestionRecords($db,$vault,static fn()=>[$threads,true]);
    check($questionReader->step(1,'n11',$scope('inbox','full'),$now+10)['added']===1,'Yalnız açık, kapsam içi soru eklenir');
    check($questionReader->step(1,'n11',$scope('inbox','incremental'),$now+10)['added']===0,'Mevcut ve yanıtlanmış soru tekrarda açılmaz');
    $stored=$db->all("SELECT remote_id,payload FROM inbox_threads WHERE company_id=1 ORDER BY remote_id");
    check(array_column($stored,'remote_id')===['already-answered','open'] && json_decode($stored[0]['payload'],true)['questionStatus']===1,'Önceden verilmiş yanıt ve mağaza ayrımı korunur');
    $payouts=new UpcomingPayoutStore($db);
    $finance=new FinanceStore($db);
    $connection=$payouts->connection(1,'n11');
    $today=gmdate('Y-m-d',$now);
    $day1=gmdate('Y-m-d',$now+86400);
    $day2=gmdate('Y-m-d',$now+2*86400);
    $days=[$day1=>5000,$day2=>7000];
    $finance->writeState(1,UpcomingPayoutAdapter::stateKey('n11'),['published'=>['days'=>[$day1=>1000],'connection'=>$connection,'checkedAt'=>$requested-60]],null);
    $manual=json_encode(['channel'=>'n11','expectedDate'=>$day2,'expectedCents'=>7000]);
    $db->run('INSERT INTO audit(company_id,action,before_value,created) VALUES(1,?,?,?)',['Hakediş silindi',$manual,gmdate('c',$requested-1)]);
    $independent=json_encode(['channel'=>'n11','expectedDate'=>$today,'actualCents'=>700,'status'=>'paid']);
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'payments',?,?)",[$independent,gmdate('c',$now)]);
    $complete=false;
    $read=static function()use($connection,$today,$days,&$complete){return ['complete'=>$complete,'cursor'=>compact('connection','today','days')];};
    $reader=new NewPayoutRecords($db,$vault,$read);
    $pending=$reader->step(1,'n11',$scope('payments','full'),$now+10);
    check(!$pending['done'] && $finance->state(1,NewPayoutRecords::stateKey('n11'))===null,'Yarım hakediş okuması yayımlanmaz');
    $complete=true;
    check($reader->step(1,'n11',$scope('payments','incremental'),$now+10)['added']===0,'Artımlı işlem silinen ödeme tarihini açamaz');
    $reader->step(1,'n11',$scope('payments','full'),$now+10);
    check(array_column($payouts->rows(1,['n11'],$today),'amountCents','date')===$days,'Tam okuma kaynağın halen bildirdiği gün ve tutarı alır');
    $db->run('INSERT INTO audit(company_id,action,before_value,created) VALUES(1,?,?,?)',['Hakediş silindi',$manual,gmdate('c',$requested+1)]);
    check(array_column($payouts->rows(1,['n11'],$today),'amountCents','date')===[$day1=>5000],'Tıklamadan sonra silinen ödeme tarihi baskın kalır');
    $automatic=UpcomingPayoutAdapter::stateKey('n11');
    $finance->writeState(1,$automatic,['published'=>['days'=>[$day1=>3200],'connection'=>$connection,'checkedAt'=>$now]],$finance->state(1,$automatic));
    $reader->step(1,'n11',$scope('payments','full'),$now+10);
    check(array_column($payouts->rows(1,['n11'],$today),'amountCents','date')===[$day1=>3200],'Daha yeni otomatik okuma eski manuel işten etkilenmez');
    check($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='payments'")['payload']===$independent,'Manuel ödeme/onay kaydı değişmez');
    check($payouts->rows(2,['n11'],$today)===[],'Ödeme gelirleri ikinci mağazaya görünmez');
    $claim=['id'=>'claim-1','orderNumber'=>'order-1','createdAt'=>gmdate('c',$requested-3600),'type'=>'return','status'=>'waiting'];
    $updated=array_replace($claim,['status'=>'approved']);
    $snapshot=['returnRequests'=>['items'=>[$claim],'from'=>gmdate('c',$now-86400),'through'=>gmdate('c',$requested-120),'checkedAt'=>gmdate('c',$requested-60)]];
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_n11',?,?)",[json_encode($snapshot),gmdate('c')]);
    $returns=new NewReturnRecords($db,$vault,new DeliveryRepository($db,new DeliveryCipher($keyPath)),static fn()=>[[$updated],true]);
    $returns->step(1,'n11',$scope('returns','incremental'),$now+10);
    check($finance->state(1,'new_ret_n11')===null,'Bilinen talep artımlıda değiştirilmez');
    $returns->step(1,'n11',$scope('returns','full'),$now+10);
    $visible=NewReturnRecords::merge($db,1,'n11',[4=>$claim],gmdate('c',$requested-60),gmdate('c',$requested-120));
    check(count($visible)===1 && $visible[4]['status']==='approved','Tam işlemin yeniden gözlediği eski iade ayrı satır oluşturmadan güncellenir');
    $fresh=NewReturnRecords::merge($db,1,'n11',[$claim],gmdate('c',time()+1),gmdate('c',time()));
    check($fresh===[$claim],'Daha yeni otomatik iade sonucu esas alınır');
    check(NewReturnRecords::merge($db,2,'n11',[],null)===[],'İade bilgisi şirket sınırını aşamaz');
    echo "Soru, hakediş ve iade için artımlı/tam kaynak, eksik sayfa, silme, yeni otomatik okuma, iade tekilleştirme ve mağaza yalıtımı geçti.\n";
} finally {unlink($keyPath);}
