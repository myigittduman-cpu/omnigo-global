<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database,DeliverySchema,FinanceSchema,HttpError,IntegrationVault,MarketplaceServiceHealth,QuestionReadTransport,RecentOrderSchema,Repository,ReturnRequestList,SyncQueue,TrendyolClaimAdapter,TrendyolClaimClient,TrendyolClaimStore};

function check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$db = new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
FinanceSchema::install($db);
DeliverySchema::install($db);
RecentOrderSchema::install($db);
$repo = new Repository($db);
$company = $repo->createCompany('Birinci müşteri');
$other = $repo->createCompany('İkinci müşteri');
$admin = ['id'=>1,'role'=>'admin'];
$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo','hash','admin',NULL)");
$db->run("INSERT INTO users VALUES(2,'client@example.test','Müşteri','hash','client',?)",[$company]);
$key = tempnam(sys_get_temp_dir(),'omnigo-claims-');
file_put_contents($key,random_bytes(32));

try {
    $vault = new IntegrationVault($db,$key);
    $config = ['enabled'=>true,'supplierId'=>'35','apiKey'=>'fixture','apiSecret'=>'fixture','revision'=>'one'];
    $vault->save($company,$config,'trendyol');
    $now = gmdate('c');
    $sync = ['lastSuccessAt'=>$now,'ordersPublished'=>true,'watermark'=>$now];
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_trendyol',?,?)",[$company,json_encode($sync),$now]);
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sync_trendyol',?,?)",[$other,json_encode($sync),$now]);
    $claimAt = (time()-86400)*1000;
    $claimDate = gmdate('c',intdiv($claimAt,1000));
    $order = ['externalId'=>'TY-1','createdAt'=>$claimDate,'products'=>[
        ['lineId'=>'51','quantity'=>1,'title'=>'Örnek ürün','returnCents'=>2647,'shippingState'=>'returned'],
        ['lineId'=>'52','quantity'=>1,'title'=>'Başka ürün','returnCents'=>3080,'shippingState'=>'returned'],
    ]];
    $db->run("INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,'trendyol','TY-1',?,?)",[$company,json_encode($order),$now]);
    $orderState = $db->one("SELECT payload,version FROM records WHERE kind='sync_trendyol' AND company_id=?",[$company]);

    $calls = [];
    $created = true;
    $fail = false;
    $transport = new QuestionReadTransport(static function($url,$headers,$deadline,$body)use(&$calls,&$created,&$fail,$claimAt){
        check(parse_url($url,PHP_URL_HOST)==='apigw.trendyol.com' && str_ends_with(parse_url($url,PHP_URL_PATH),'/35/claims'),'Resmî satıcı kapsamı');
        check($body===null && $deadline>time(),'Yalnız süre kısıtlı GET');
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        check($query['endDate']-$query['startDate']<TrendyolClaimClient::WINDOW,'Sınırlı tarih aralığı');
        $calls[]=$query;
        if($fail)return ['status'=>429,'retryAfter'=>120];
        $inWindow=$claimAt>=$query['startDate'] && $claimAt<=$query['endDate'];
        $matches=($query['claimItemStatus']??null)==='Created' ? $created : !$created;
        $items=$inWindow&&$matches?[[
            'id'=>'claim-1','claimId'=>'claim-1','claimDate'=>$claimAt,'lastModifiedDate'=>$claimAt+($created?0:1000),
            'orderNumber'=>'TY-1','customerFirstName'=>'Gizli ad','items'=>[[
                'orderLine'=>['id'=>850,'productName'=>'Örnek ürün','price'=>999],
                'claimItems'=>[['id'=>'unit-1','orderLineItemId'=>51,'claimItemStatus'=>['name'=>$created?'Created':'Accepted'],
                    'customerNote'=>'Gizli not','customerClaimItemReason'=>['name'=>'Diğer']]],
            ]],
        ]]:[];
        return ['status'=>200,'body'=>json_encode(['content'=>$items?:null,'page'=>(int)$query['page'],
            'totalPages'=>count($items),'totalElements'=>count($items),'size'=>$items?(int)$query['size']:0])];
    });

    $adapter = new TrendyolClaimAdapter($db,$vault,new TrendyolClaimClient($transport));
    $queue = new SyncQueue($db,[TrendyolClaimAdapter::JOB=>$adapter]);
    $queue->enqueue($company,'Trendyol');
    $queue->enqueue($company,TrendyolClaimAdapter::JOB);
    $ordersJob = $db->one("SELECT * FROM jobs WHERE company_id=? AND provider='Trendyol'",[$company]);
    $run = static function()use($db,$company,$queue):array{
        $db->run('UPDATE jobs SET next_run=0 WHERE company_id=? AND provider=?',[$company,TrendyolClaimAdapter::JOB]);
        check($queue->runOne(null,[TrendyolClaimAdapter::JOB],time()+10),'İade adımı çalıştı');
        return $db->one('SELECT * FROM jobs WHERE company_id=? AND provider=?',[$company,TrendyolClaimAdapter::JOB]);
    };
    $store = new TrendyolClaimStore($db);
    $list = new ReturnRequestList($db);
    for($pass=0;$pass<4;$pass++){
        $job=$run();
        check(($job['attempts']??-1)==0,'İade okuması başarılı');
        if($pass<3)check(!isset($store->read($company)['returnRequests']),'Kısmi tarama yayımlanmaz');
    }
    $report = $list->listing($admin,$company,['provider'=>'trendyol'],true);
    check($report['total']===2 && $report['sources'][0]['coverage']==='claims','Talep ve farklı eski iade satırı bir arada');
    $pending = $list->listing($admin,$company,['provider'=>'trendyol','status'=>'pending'],true);
    check($pending['total']===1 && $pending['items'][0]['lineId']==='51' && $list->pendingCount($company)===1,'Canlı talep doğru sipariş biriminde tek kez sayılır');
    check($pending['items'][0]['amountCents']===null && $pending['items'][0]['createdAt']===$claimDate,'Henüz ödeme tutarı oluşmadı, kaynak tarihi korunur');
    $snapshot = $store->read($company)['returnRequests'];
    check(!str_contains(json_encode($snapshot,JSON_UNESCAPED_UNICODE),'Gizli'),'Kişisel ad ve notlar depolanmaz');
    check($list->listing($admin,$other,['provider'=>'trendyol'],true)['total']===0,'Başka firmanın iade listesine sızmaz');
    try {
        $list->listing(['id'=>2,'role'=>'client','company_id'=>$company],$company,['provider'=>'trendyol']);
        throw new RuntimeException('Müşteri iade talep listesine erişti');
    } catch(HttpError $error) { check($error->getCode()===403,'Mevcut rol sınırı'); }

    $fail=true;
    $retry=$run();
    check($retry['state']==='pending' && $retry['attempts']==1 && $retry['next_run']>=time()+119,'429 sonrası Retry-After korunur');
    check($store->read($company)['returnRequests']===$snapshot,'Hata son başarılı görünümü değiştirmez');
    $fail=false;
    $created=false;
    for($pass=0;$pass<4;$pass++)$job=$run();
    check($list->pendingCount($company)===0,'Düzelen bağlantı kabul edilen talebi günceller');
    check($db->one("SELECT payload,version FROM records WHERE kind='sync_trendyol' AND company_id=?",[$company])===$orderState,'Sipariş aktarım durumu bire bir korunur');
    check($db->one("SELECT * FROM jobs WHERE company_id=? AND provider='Trendyol'",[$company])===$ordersJob,'Sipariş kuyruğu bire bir korunur');
    $health = new MarketplaceServiceHealth($db);
    check($health->channel($company,'trendyol',$sync,[TrendyolClaimAdapter::JOB=>$job],true,true)['requests']['status']==='ready','Bağlantı kontrolü tamamlanmış iade taramasını gösterir');
    $vault->save($company,[...$config,'revision'=>'two'],'trendyol');
    check($store->read($company)===[],'Bağlantı değişince önceki kaynağın talepleri gösterilmez');
} finally {
    unlink($key);
}
echo "Trendyol iade sayfalama kapsamı, kaynak kimliği, yeniden deneme ve rol ayrımı doğrulandı.\n";
