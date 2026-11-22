<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InboxRepository,MarketplaceQuestionMapper,IdefixQuestionClient,N11QuestionClient,QuestionReadTransport,SyncFailure,InboxStatus};
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Diğer')");$db->run("INSERT INTO users VALUES(1,'test@example.test','Yönetici','x','admin',NULL)");$repo=new InboxRepository($db);
$raw=['id'=>1,'product'=>'Ürün','question'=>'Ne zaman gelir?','productQuestionAnswer'=>[],'createdAt'=>'2026-11-11T15:00:00+03:00'];
$thread=MarketplaceQuestionMapper::idefix($raw,'product');$id=$repo->save(1,$thread,'idefix');$repo->save(1,$thread,'idefix');
check($repo->summary(1,company:1)['waitingCount']===1,'Tekrarlı sayfa soruyu çoğaltmaz');check($repo->summary(1,company:2)['waitingCount']===0,'Firma ayrımı');
$raw['productQuestionAnswer']=[['id'=>7,'answerBody'=>'Yarın']];$repo->save(1,MarketplaceQuestionMapper::idefix($raw,'product'),'idefix');
check($repo->summary(1,company:1)['waitingCount']===0&&count($repo->summary(1,company:1,status:'answered')['items'])===1,'Yanıtlanan sorunun bekleyen sayısı düşer');check($repo->detail($id)['messages'][1]['dateUnavailable'],'Olmayan yanıt saati uydurulmaz');
$order=['id'=>2,'orderNumber'=>'IDE123','reason'=>'Kargo','createdAt'=>'2026-11-11T15:00:00+03:00','lastMessage'=>'Nerede?','lastMessageDate'=>'2026-11-11T15:00:00+03:00'];$repo->save(1,MarketplaceQuestionMapper::idefix($order,'unanswered'),'idefix');
$order['lastMessage']='Kargoya verildi';$order['lastMessageDate']='2026-11-11T16:00:00+03:00';$repo->save(1,MarketplaceQuestionMapper::idefix($order,'answered'),'idefix');check($repo->summary(1,company:1)['waitingCount']===0,'Sipariş sorusu yanıtlandığında listeden çıkar');
$n11=['question'=>'Stok var mı?','questionDate'=>'2026-11-11','productTitle'=>'n11 Ürün','status'=>'OPEN','answer'=>''];$repo->save(1,MarketplaceQuestionMapper::n11($n11,'123'),'n11');check($repo->summary(1,company:1)['waitingCount']===1,'n11 sorusu doğru platformda');
$n11['status']='CLOSED';$n11['answer']='Var';$n11['answeredDate']='2026-11-11';$repo->save(1,MarketplaceQuestionMapper::n11($n11,'123'),'n11');check($repo->summary(1,company:1)['waitingCount']===0,'n11 kapatılan soru bekleyenlerden çıkar');
$rejected=$thread;$rejected['questionStatus']=3;$repo->save(1,$rejected,'idefix');check(count($repo->summary(1,company:1,status:'answered')['items'])===2,'Reddedilen soru listeden kaldırılır');
$calls=[];$transport=new QuestionReadTransport(function($url,$headers,$deadline,$xml)use(&$calls){$calls[]=[$url,$xml];return ['status'=>200,'body'=>'<Envelope><Body><GetProductQuestionListResponse><result><status>success</status></result><productQuestions><productQuestion><id>123</id></productQuestion></productQuestions><pagingData><currentPage>0</currentPage><pageCount>1</pageCount></pagingData></GetProductQuestionListResponse></Body></Envelope>'];});
$list=(new N11QuestionClient($transport))->listing(['apiKey'=>'test&key','apiSecret'=>'secret'],0,time()+20);check($list['ids']===['123']&&$list['complete']&&str_contains($calls[0][1],'test&amp;key'),'SOAP kimlik güvenliği ve sayfalama');
$transport=new QuestionReadTransport(fn()=>['status'=>429,'body'=>'','retryAfter'=>80]);try{(new IdefixQuestionClient($transport))->page(['vendorId'=>'1','apiKey'=>'k','apiSecret'=>'s'],'product',1,time()+20);throw new RuntimeException('Hız sınırı yok sayıldı');}catch(SyncFailure $e){check($e->retryAfter===80,'Retry-After korunur');}
echo "n11/idefix sorular: tekilleştirme, firma ayrımı, cevapla azalan sayaç, red filtresi, gerçek tarih ve API hız sınırı geçti.\n";
