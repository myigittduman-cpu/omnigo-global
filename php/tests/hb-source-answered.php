<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,HepsiburadaQuestionClient,HepsiburadaQuestionMapper,InboxHepsiburadaReplyVerification,InboxRepository,InboxService,InboxWorker,IntegrationVault,MarketplaceQuestionMapper,QuestionReadTransport};

function expectValue(mixed $actual,mixed $expected,string $scope):void{
    if($actual!==$expected)throw new RuntimeException($scope.' '.json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));
}
$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$db->run("INSERT INTO companies VALUES(1,'Örnek A'),(2,'Örnek B')");
$db->run("INSERT INTO users VALUES(1,'test@example.invalid','Yönetici','hash','admin',NULL)");
$repo=new InboxRepository($db);
$now=time();
$stamp=static fn(int $offset):string=>gmdate('c',$now+$offset);
$jobId=static fn(int $id):string=>sprintf('%048x',$id);
$question=static function(string $remote,int $status=1,int $last=-800,?int $previous=null,bool $partial=false)use($stamp):array{
    $messages=[];
    if($previous!==null)$messages[]=MarketplaceQuestionMapper::message('Q0','İlk soru',$stamp($previous));
    $messages[]=MarketplaceQuestionMapper::message('Q1','Güncel soru',$stamp($last));
    if($status===1)$messages[]=MarketplaceQuestionMapper::message('A1','Pazaryeri yanıtı',$stamp($last+20),true);
    return MarketplaceQuestionMapper::thread($remote,'Ürün',$messages,$status,['expiresAt'=>$stamp(900),'partial'=>$partial]);
};
$job=static function(int $num,int $thread,int $offset,string $error,int $company=1,string $kind='reply')use($db,$stamp,$jobId):string{
    $id=$jobId($num);
    $db->run("INSERT INTO inbox_jobs(id,company_id,thread_id,kind,user_id,payload,state,next_run,created,error) VALUES(?,?,?,?,1,?,'failed',0,?,?)",
        [$id,$company,$thread,$kind,json_encode(['body'=>'Gönderilmeyen taslak']),$stamp($offset),$error]);
    return $id;
};
$changed=InboxHepsiburadaReplyVerification::CHANGED_BEFORE_SEND;
$genuine='İşlem tamamlanamadı. Bağlantı yetkisini veya konuşmayı kontrol edin.';
$answered=$repo->save(1,$question('10'),'hepsiburada');
$old=$job(1,$answered,-1100,$changed);
$otherFailure=$job(2,$answered,-400,$genuine);
$notSent=$job(3,$answered,-300,$changed);
$waiting=$repo->save(1,$question('11',0),'hepsiburada');$job(4,$waiting,-400,$changed);
$otherProvider=$repo->save(1,$question('12'),'n11');$job(5,$otherProvider,-400,$changed);
$otherStore=$repo->save(2,$question('10'),'hepsiburada');$foreign=$job(6,$otherStore,-200,$changed,2);
$followed=$repo->save(1,$question('13',1,-100,-900),'hepsiburada');$earlier=$job(7,$followed,-400,$changed);
$partial=$repo->save(1,$question('14',1,-800,null,true),'hepsiburada');$job(8,$partial,-400,$changed);
$detailJob=$job(9,$answered,-500,$changed,1,'detail');
$tied=$repo->save(1,$question('15'),'hepsiburada');$job(10,$tied,-800,$changed);

$detail=$repo->detail($answered);
expectValue([$detail['replyState'],$detail['status'],$detail['canReply']],['source_answered','answered',false],'Konuşma ana durumu');
$jobs=array_column($detail['jobs'],null,'id');
expectValue([$jobs[$notSent]['state'],$jobs[$notSent]['error'],$jobs[$otherFailure]['state'],$jobs[$old]['state'],$jobs[$detailJob]['state']],
    ['source_answered',null,'failed','failed','failed'],'Geçmiş girişimlerin farklı durumları');
expectValue($repo->detail($otherProvider)['replyState'],'failed','Diğer pazaryeri');
expectValue($repo->detail($waiting)['replyState'],'failed','Açık konuşmada gerçek başarısızlık');
expectValue($repo->detail($partial)['replyState'],'failed','Kısmi geçmiş');
expectValue($repo->detail($tied)['replyState'],'failed','Aynı saniye kaynak sırası belirsiz');
expectValue($repo->detail($followed)['replyState']??null,null,'Yeni soruya eski başarısızlık iliştirilmez');
$table=$repo->summary(1,0,false,1,'answered','hepsiburada','',10,'newest',true);
$items=array_column($table['items'],null,'threadId');
expectValue([$items[$answered]['replyState'],isset($items[$answered]['replyError']),isset($items[$followed]['replyState'])],['source_answered',false,false],'Tablo ve indirme DTO');
$activity=array_column($table['jobs'],null,'id');
expectValue([$activity[$old]['state'],$activity[$earlier]['state'],$activity[$otherFailure]['state'],$activity[$notSent]['state'],isset($activity[$foreign])],
    ['failed','failed','failed','source_answered',false],'Faaliyet, güncel soru ve mağaza çiti');
expectValue($repo->waitingCount(1,'hepsiburada'),1,'Yalnız açık soru bekleyen sayılır');
expectValue($table['unreadCount'],2,'Mağazanın okunmamış bekleyenleri yalnızca açık HB ve ayrı n11 girişimidir');
$latest=$db->one('SELECT id FROM inbox_messages WHERE thread_id=? AND remote_id=?',[$followed,'Q1'])['id'];
$repo->markRead(1,(int)$latest,1);
$job(11,$followed,-60,$changed);
$unread=$repo->summary(1,0,true,1,'answered','hepsiburada','',10,'newest',true);
$items=array_column($unread['items'],null,'threadId');
expectValue([$items[$followed]['question'],isset($items[$followed]['replyState']),$items[$followed]['canReply']],['İlk soru',false,false],'Eski okunmamış satıra güncel yanıt iliştirilmez');
expectValue($db->one('SELECT state,error FROM inbox_jobs WHERE id=?',[$notSent]),['state'=>'failed','error'=>$changed],'İş geçmişi korunur');

$key=tempnam(sys_get_temp_dir(),'hb-source-');
try{
    file_put_contents($key,random_bytes(32));
    $vault=new IntegrationVault($db,$key);$vault->save(1,['enabled'=>true],'hepsiburada');
    $open=$question('99',0,-600);$threadId=$repo->save(1,$open,'hepsiburada');
    $next=(new InboxService($db))->enqueue(1,1,'reply',['body'=>'Taslak','fingerprint'=>$open['fingerprint'],'recipients'=>[]],$threadId);
    $client=new class($question('99',1,-600)) {
        public int $writes=0;
        public function __construct(private array $snapshot){}
        public function detail(array $config,string $remote,int $deadline):array{return $this->snapshot;}
        public function reply(...$args):array{$this->writes++;throw new LogicException('Gönderim yapılmamalı.');}
    };
    (new InboxWorker($db,$vault,fn()=>$client))->runOne();
    $current=$repo->detail($threadId);
    expectValue([$client->writes,$current['replyState'],$current['jobs'][0]['id'],$current['jobs'][0]['error']],
        [0,'source_answered',$next,null],'Yeni girişimde gerçek yanıt okunur ve taslak iletilmez');
    $merchant='f34870e5-744a-44e1-a40f-a1cb62ef15de';
    $vault->save(1,['enabled'=>true,'merchantId'=>$merchant,'username'=>'test','serviceKey'=>'test','userAgent'=>'firm_test'],'hepsiburada');
    $raw=['issueNumber'=>'100','merchant'=>['id'=>$merchant],'status'=>'WaitingForAnswer','createdAt'=>$stamp(-500),'expireDate'=>$stamp(900),
        'conversations'=>[['id'=>'Q','from'=>'Customer','content'=>'Yeni soru','createdAt'=>$stamp(-500)]]];
    $snapshot=HepsiburadaQuestionMapper::thread($raw,$merchant);$threadId=$repo->save(1,$snapshot,'hepsiburada');
    $next=(new InboxService($db))->enqueue(1,1,'reply',['body'=>'Yeni taslak','fingerprint'=>$snapshot['fingerprint'],'recipients'=>[]],$threadId);
    $reads=0;$writes=0;
    $transport=new QuestionReadTransport(static function()use(&$reads,$raw,$stamp){
        if(++$reads===2){$raw['status']='Answered';$raw['conversations'][]=['id'=>'A','from'=>'Merchant','content'=>'Panelden yanıt','createdAt'=>$stamp(-2)];}
        return ['status'=>200,'body'=>json_encode($raw)];
    });
    $adapter=new HepsiburadaQuestionClient($transport,static function()use(&$writes){$writes++;throw new LogicException('POST beklenmiyor.');});
    (new InboxWorker($db,$vault,fn()=>$adapter))->runOne();
    $current=$repo->detail($threadId);
    expectValue([$reads,$writes,$current['status'],$current['replyState'],$current['jobs'][0]['id']],
        [2,0,'answered','source_answered',$next],'Son resmi GET ilk kez yanıtlı ise kayıt doğrulanır, POST yapılmaz');
}finally{if(is_file($key))unlink($key);}
echo "Hepsiburada kaynak-yanıtlı durumunun liste, işlem geçmişi, konuşma, sayaç ve POST öncesi işleyiş testleri geçti.\n";
