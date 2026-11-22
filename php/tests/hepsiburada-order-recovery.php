<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, DeliveryCipher, DeliveryRepository, DeliverySchema, HepsiburadaCredentials, HepsiburadaOrderAdapter, HepsiburadaReadClient, IntegrationVault, NotificationSchema, RecentOrderSchema, ShipmentOrderIngestor, ShipmentSchema, SyncFailure};

function expect(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function failsWith(callable $run,int $status):void {
    try{$run();}catch(SyncFailure $failure){expect($failure->httpStatus===$status,'Beklenmeyen hata kodu');return;}
    throw new RuntimeException('Geçersiz sayfa kabul edildi.');
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
DeliverySchema::install($db); ShipmentSchema::install($db); NotificationSchema::migrate($db); RecentOrderSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Mağaza'),(2,'Diğer Mağaza')");
ShipmentOrderIngestor::activate($db,1,time()-86400);
$key=tempnam(sys_get_temp_dir(),'hb-recovery-');
file_put_contents($key,random_bytes(32));
try{
    $vault=new IntegrationVault($db,$key);
    $merchant='12345678-1234-1234-1234-123456789012';
    $config=['enabled'=>true,'ordersEnabled'=>true,'revision'=>'qa','notifyFrom'=>time(),'merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'fixture','userAgent'=>'fixture_dev'];
    $vault->save(1,$config,'hepsiburada');
    $credentials=HepsiburadaCredentials::fromArray($config);
    $tail=['offset'=>110,'limit'=>10,'totalCount'=>105,'pageCount'=>11,'items'=>null];
    $readPage=function(array $data,int $offset=110)use($credentials){
        $client=new HepsiburadaReadClient(fn()=>['status'=>200,'body'=>json_encode($data,JSON_THROW_ON_ERROR)]);
        return $client->page($credentials,'delivered',$offset,time()+15);
    };
    foreach([105,110] as $count){
        $end=$readPage([...$tail,'totalCount'=>$count]);
        expect($end['items']===[]&&!$end['hasMore'],'Doğrulanmış akış sonu yanlış yorumlandı.');
    }
    failsWith(fn()=>$readPage([...$tail,'totalCount'=>115,'pageCount'=>12]),502);
    foreach([['offset'=>100],['limit'=>1],['pageCount'=>10],['totalCount'=>'105'],['totalCount'=>-1]] as $invalid){
        failsWith(fn()=>$readPage([...$tail,...$invalid]),422);
    }
    foreach(['items','offset','limit','pageCount','totalCount'] as $required){
        $missing=$tail;unset($missing[$required]);failsWith(fn()=>$readPage($missing),422);
    }
    $date=gmdate('c',time()-600);
    $pending=array_map('strval',range(9100000100,9100000113));
    $new=array_map('strval',range(9100000114,9100000117));
    $marker=['watermark'=>$date,'feedPosition'=>4,'offsets'=>['delivered'=>110],'pendingOrders'=>array_fill_keys($pending,true)];
    $before=json_encode($marker,JSON_THROW_ON_ERROR);
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(1,'sync_hepsiburada',?,?)",[$before,$date]);
    $other='{"phase":"live","fixture":"other"}';
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(2,'sync_hepsiburada',?,?)",[$other,$date]);
    $readState=fn(int $company)=>$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company])['payload'];
    $retryFails=true;$shrunk=false;$offsets=[];
    $reader=new HepsiburadaReadClient(function($url)use($merchant,$date,$new,$tail,&$offsets,&$retryFails,&$shrunk){
        $path=parse_url($url,PHP_URL_PATH);$params=[];parse_str(parse_url($url,PHP_URL_QUERY)??'',$params);
        $result=[];
        if(str_ends_with($path,'/delivered')){
            $offset=(int)$params['offset'];$offsets[]=$offset;
            if($offset===112&&$retryFails)return ['status'=>200,'body'=>'{}'];
            if($offset===110||$offset===112){
                $rows=array_slice($new,$offset-110,2);
                $result=['items'=>array_map(fn($number)=>['merchantId'=>$merchant,'orderNumber'=>$number],$rows),'totalCount'=>114];
            }
            if($offset===110&&$shrunk)$result=$tail;
        }elseif(str_contains($path,'/ordernumber/')){
            $number=basename($path);
            $result=['orderNumber'=>$number,'orderDate'=>$date,'items'=>[[
                'id'=>'line-'.$number,'merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Örnek','merchantSKU'=>'QA','quantity'=>1,
                'totalPrice'=>['currency'=>'TRY','amount'=>'100.00'],'status'=>'Packaged','lastStatusUpdateDate'=>$date,
                'customerName'=>'Örnek','shippingAddress'=>['name'=>'Örnek','phoneNumber'=>'05000000000','address'=>'Örnek adres','city'=>'Ankara','town'=>'Çankaya'],
            ]]];
        }
        return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
    });
    $adapter=new HepsiburadaOrderAdapter($db,$vault,new DeliveryRepository($db,new DeliveryCipher($key)),$reader);
    failsWith(fn()=>$adapter->syncReadOnly(1,time()+50),422);
    expect($offsets===[110,112]&&$readState(1)===$before,'Yarım sayfanın güncellemeleri ve imleç hata halinde birlikte korunur.');
    $retryFails=false;$offsets=[];$adapter->syncReadOnly(1,time()+50);
    expect($offsets===[110,112],'Kısa sayfadan sonraki ilk kayıt atlandı.');
    $wanted=array_merge($pending,$new);
    $stored=array_column($db->all("SELECT external_id FROM recent_orders WHERE company_id=1 AND provider='hepsiburada' ORDER BY external_id"),'external_id');
    expect($stored===$wanted,'Kayıtlı bekleyenlerden veya kaynaktaki yeni kayıtlardan eksik var.');
    foreach(['delivery_orders','shipment_entries'] as $table)expect((int)$db->one("SELECT COUNT(*) n FROM $table WHERE company_id=1")['n']===count($wanted),'Sipariş operasyona aktarılmadı.');
    $after=json_decode($readState(1),true,64,JSON_THROW_ON_ERROR);
    expect(empty($after['pendingOrders'])&&$after['phase']==='live'&&$after['watermark']>$date,'Tamamlama tarihi güncellenmedi.');
    // The exact live tail: a previously persisted cursor at 110 and 14
    // pending details remain valid when the provider total falls to 105.
    $after['nextRequestAt']=0;$after['offsets']['delivered']=110;
    $after['pendingOrders']=array_fill_keys($pending,true);$after['feedPosition']=4;
    $shrunk=true;$offsets=[];
    $db->run("UPDATE records SET payload=? WHERE kind='sync_hepsiburada' AND company_id=1",[json_encode($after,JSON_THROW_ON_ERROR)]);
    $adapter->syncReadOnly(1,time()+50);
    $completed=json_decode($readState(1),true,64,JSON_THROW_ON_ERROR);
    expect($offsets===[110]&&$completed['offsets']['delivered']===0&&empty($completed['pendingOrders'])&&$completed['phase']==='live','Daralan son sayfa bekleyen siparişleri durdurdu.');
    $completed['nextRequestAt']=0;$offsets=[];
    $db->run("UPDATE records SET payload=? WHERE kind='sync_hepsiburada' AND company_id=1",[json_encode($completed,JSON_THROW_ON_ERROR)]);
    $adapter->syncReadOnly(1,time()+50);
    expect($offsets===[0],'Sonraki otomatik tur yenilenen akışı başlangıçtan okumadı.');
    expect((int)$db->one("SELECT COUNT(*) n FROM recent_orders WHERE company_id=1")['n']===count($wanted),'Otomatik sonraki okuma siparişi çoğalttı.');
    expect($readState(2)===$other&&!$db->one("SELECT id FROM recent_orders WHERE company_id=2"),'Diğer mağaza etkilendi.');
    echo "Hepsiburada kurtarma: 110/112 imleçleri, 14 bekleyen + 4 yeni, üç operasyon tablosu, tekrar okuma ve mağaza izolasyonu geçti.\n";
}finally{unlink($key);}
