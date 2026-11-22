<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,Repository,IntegrationVault,PazaramaConnection,PazaramaAuthClient,PazaramaReadClient,PazaramaSyncAdapter,PazaramaQuestionAdapter,PazaramaSalesMapper,PazaramaQuestionMapper,SalesAnalytics,SyncFailure,HttpError,InboxRepository,NotificationFeed,SupportSchema,NotificationSchema,SyncQueue};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $call,int $status):void{try{$call();}catch(SyncFailure|HttpError $e){check(($e instanceof SyncFailure?$e->httpStatus:$e->getCode())===$status,'Beklenen hata');return;}throw new RuntimeException('İşlem reddedilmeliydi.');}
function uuid(int $id):string{return sprintf('00000000-0000-0000-0000-%012d',$id);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));Omnigo\FinanceSchema::install($db);SupportSchema::migrate($db);NotificationSchema::migrate($db);Omnigo\DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Deneme');$other=$repo->createCompany('Diğer');
$db->run('INSERT INTO users(email,name,password,role) VALUES(?,?,?,?)',['test@test.invalid','Yönetici','unused','admin']);$user=['id'=>$db->insertId(),'role'=>'admin'];
$key=tempnam(sys_get_temp_dir(),'paza-test-');file_put_contents($key,random_bytes(32));
$zone=new DateTimeZone('Europe/Istanbul');$start=new DateTimeImmutable('first day of this month 00:00:00',$zone);$month=$start->format('Y-m');
$raw=['orderId'=>uuid(10),'orderDate'=>$start->format('Y-m-d H:i'),'currency'=>'TL','orderAmount'=>199.99,'customerName'=>'PRIVATE','items'=>[['orderItemId'=>uuid(11),'quantity'=>2,'orderItemStatus'=>12,'totalPrice'=>['value'=>199.99,'valueInt'=>19999,'currency'=>'TL'],'product'=>['name'=>'Örnek ürün','stockCode'=>'SKU','imageURL'=>null]]]];
try{
 $vault=new IntegrationVault($db,$key);$connection=new PazaramaConnection($db,$vault);$connection->save($company,['sellerId'=>uuid(1),'apiKey'=>'fake','apiSecret'=>'fake','version'=>0],$user['id']);
 $token='eyJhbGciOiJub25lIn0.'.rtrim(strtr(base64_encode(json_encode(['client_seller_id'=>uuid(1)])),'+/','-_'),'=').'.test';
 $auth=new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>3600])]);
 $mapped=PazaramaSalesMapper::order($raw);check($mapped['grossCents']===19999&&$mapped['products'][0]['netQuantity']===2,'Satır toplamı miktarla yeniden çarpılmaz');check(!str_contains(json_encode($mapped),'PRIVATE'),'Kişisel bilgiler satış verisine girmez');
 foreach([6=>'cancelCents',13=>'cancelCents',8=>'returnCents',10=>'returnCents'] as $status=>$field){$changed=$raw;$changed['items'][0]['orderItemStatus']=$status;check(PazaramaSalesMapper::order($changed)[$field]===19999,'İptal ve iade durumları');}
 foreach([18,7,9] as $status){$changed=$raw;$changed['items'][0]['orderItemStatus']=$status;check(PazaramaSalesMapper::order($changed)['returnCents']===0,'Bekleyen/reddedilen iade düşülmez');}
 fails(fn()=>PazaramaSalesMapper::order([...$raw,'orderAmount'=>1]),422);
 fails(fn()=>PazaramaSalesMapper::unique([$mapped,$mapped],$start->format(DATE_ATOM),$start->modify('+1 day')->format(DATE_ATOM)),422);
 $rate=false;$requests=[];
 $reader=new PazaramaReadClient(function($url,$headers,$body)use($raw,&$rate,&$requests){
   $query=json_decode($body,true);$requests[]=$query;if($rate)return ['status'=>429,'retryAfter'=>7200];
   check($url==='https://isortagimapi.pazarama.com/order/getOrdersForApi','Sabit salt okunur adres');
   check(strtotime($query['endDate'])-strtotime($query['startDate'])<=14*86400,'Sınırlı tarih aralığı');
   $items=[];if(substr($raw['orderDate'],0,10)===substr($query['startDate'],0,10))for($i=0;$i<($query['pageNumber']===1?20:1);$i++)$items[]=[...$raw,'orderId'=>uuid(100+$i+($query['pageNumber']-1)*20)];
   return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>$items])];
 });
 $delivery=Omnigo\DeliveryMapper::pazarama([...$raw,'customerName'=>'Sipariş veren','shipmentAddress'=>['nameSurname'=>'Alıcı','phoneNumber'=>'05000000000','cityName'=>'Eskişehir','displayAddressText'=>'Teslimat adresi'],'billingAddress'=>['displayAddressText'=>'Fatura adresi']]);
 check($delivery['buyerName']==='Sipariş veren'&&$delivery['address']==='Teslimat adresi'&&$delivery['city']==='Eskişehir','Teslimat kişisi ve adresi doğru kaynaktan');
 $adapter=new PazaramaSyncAdapter($db,$vault,$auth,$reader,new Omnigo\DeliveryRepository($db,new Omnigo\DeliveryCipher($key)));$adapter->syncReadOnly($company,time()+30);check(!$repo->records($company,'sales',$user),'Eksik sayfa yayımlanmaz');
 $rate=true;fails(fn()=>$adapter->syncReadOnly($company,time()+30),429);$rate=false;
 for($i=0;$i<10&&!($connection->status($company)['sync']['salesPublished']??false);$i++)$adapter->syncReadOnly($company,time()+30);
 $sale=$repo->records($company,'sales',$user)[0];check($sale['orders']===21&&$sale['grossCents']===419979,'Bütün sayfalar doğru toplanır');
 foreach(['admin','client'] as $role){$data=$repo->dashboard(['role'=>$role,'company_id'=>$company],$company,$month);check($data['sales'][0]['orders']===21,'İki portal aynı satışı görür');$report=(new SalesAnalytics($db))->report(['role'=>$role,'company_id'=>$company],$company,'Pazarama',$start->format('Y-m-d'),$start->format('Y-m-d'));check($report['available']&&$report['topProducts'][0]['netQuantity']===42,'Tarih ve TOP10 raporu');}
 check((int)$db->one("SELECT COUNT(*) AS n FROM delivery_orders WHERE provider='Pazarama'")['n']===21,'Aktif siparişler teslimat listesine aktarılır');
 $reference=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company])['payload'],true)['reconciliationReference'];
 check(count($reference['orders'])===21&&(new \Omnigo\ReconciliationCompare($db,new \Omnigo\DeliveryCipher($key)))->inspect($company,'pazarama',$reference)['total']===0,'Pazarama tamamlanan kaynak mutabakatı');
 check(!$repo->records($other,'sales',$user),'Firma verisi ayrıdır');fails(fn()=>$repo->save($user,$company,'sales',$sale,$sale['id']),409);
 $connection->retry($company);check($connection->status($company)['sync']['salesPublished'],'Tekrar denemede son satış korunur');
 $question=['questionId'=>uuid(999),'productName'=>'Ürün','question'=>'Ölçüsü nedir?','questionDate'=>'2026-10-01T12:30:00.123','questionStatus'=>2,'answer'=>'60 cm.','answerDate'=>'2026-10-01T12:40:00','maskedUserName'=>'A****','userId'=>'PRIVATE'];
 $questions=new PazaramaReadClient(function($url,$headers,$body)use($question){check(str_ends_with($url,'getApprovalAnswersByMerchantSearch'),'Soru servisi salt okunur');return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['sellerId'=>uuid(1),'approvalAnswersByMerchantSearchs'=>[$question],'pageResponse'=>['pageIndex'=>1,'totalPages'=>1,'totalCount'=>1]]])];});
 $qa=new PazaramaQuestionAdapter($db,$vault,$auth,$questions);$qa->syncReadOnly($company,time()+30);$qa->syncReadOnly($company,time()+30);
 $inbox=new InboxRepository($db);check(!$inbox->summary($user['id'],0,false,$company)['items'],'Onay bekleyen cevap cevap bekleyenler listesine girmez');$summary=$inbox->summary($user['id'],0,false,$company,'approval_pending');check(count($summary['items'])===1&&$summary['items'][0]['provider']==='Pazarama','Onay bekleyen cevap ilgili sekmede görünür');
 $detail=$inbox->detail($summary['items'][0]['threadId']);check(count($detail['messages'])===2&&$detail['data']['questionStatus']===2,'Cevap ve onay durumu');check(!str_contains(json_encode($detail),'PRIVATE'),'Ek kişisel alanlar kaydedilmez');
 $feed=(new NotificationFeed($db))->listing($user['id'],$company,0);check(!$feed['items'],'Onay bekleyen cevap bildirim listesine girmez');check(!$inbox->summary($user['id'],0,false,$other,'approval_pending')['items'],'Soru firma izolasyonu');
 $inbox->markRead($user['id'],$summary['items'][0]['id'],$company);$qa->syncReadOnly($company,time()+30);check($inbox->summary($user['id'],0,false,$company,'approval_pending')['items'][0]['unread']===false,'Tekrar aktarım okunmuş soruyu açmaz');
 $rate=true;$db->run("UPDATE jobs SET next_run=0 WHERE provider='Pazarama'");(new SyncQueue($db,['Pazarama'=>$adapter]))->runOne();check($db->one("SELECT next_run FROM jobs WHERE provider='Pazarama'")['next_run']>=time()+7195,'Retry-After korunur');check($repo->records($company,'sales',$user)[0]['orders']===21,'Başarısız güncellemede eski toplam korunur');
 $badAuth=new PazaramaAuthClient(fn()=>['status'=>200,'body'=>json_encode(['access_token'=>'a.'.base64_encode(json_encode(['client_seller_id'=>uuid(2)])).'.b','token_type'=>'Bearer','expires_in'=>3600])]);fails(fn()=>(new PazaramaQuestionAdapter($db,$vault,$badAuth,$questions))->syncReadOnly($company,time()+30),403);
 // Native API uses inclusive calendar dates, not timestamp bounds.
 $historyStart=$start->modify('-1 month');$boundaryItems=[];
 foreach([13,14] as $i)$boundaryItems[]=[...$raw,'orderId'=>uuid(800+$i),'orderDate'=>$historyStart->modify('+' . $i . ' days')->setTime(11,33)->format('Y-m-d H:i')];
 $calendarReader=new PazaramaReadClient(function($url,$headers,$body)use($boundaryItems){$q=json_decode($body,true);$items=array_values(array_filter($boundaryItems,fn($o)=>substr($o['orderDate'],0,10)>=substr($q['startDate'],0,10)&&substr($o['orderDate'],0,10)<=substr($q['endDate'],0,10)));return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>$items])];});
 $stored=$db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company]);$history=json_decode($stored['payload'],true);$history['startDate']=$historyStart->format(DATE_ATOM);unset($history['scan']);$db->run('UPDATE records SET payload=? WHERE id=?',[json_encode($history),$stored['id']]);
 $calendarAdapter=new PazaramaSyncAdapter($db,$vault,$auth,$calendarReader);
 for($cycle=0;$cycle<2;$cycle++){for($page=0;$page<10;$page++){$calendarAdapter->syncReadOnly($company,time()+30);$state=json_decode($db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company])['payload'],true);if(!isset($state['scan']))break;}check(!isset($state['scan'])&&(int)$db->one("SELECT COUNT(*) n FROM records WHERE company_id=? AND kind='order_pazarama'",[$company])['n']===2,'Tarih sınırındaki siparişler kayıpsız ve tekrarsız; tekrar tarama idempotent');}
 echo "Pazarama: sayfalama, atomik yayın, tutarlar, hata ve hız sınırı, firma izolasyonu, iki portal, raporlar, soru/cevap ve bildirim tekrarları geçti.\n";
}finally{unlink($key);}
