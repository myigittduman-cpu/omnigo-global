<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Omnigo\{Database, FinanceClient, FinanceSchema, FinanceStore, HttpError, IntegrationVault, N11FinanceClient, PaymentCalendar,
    PazaramaAuthClient, Repository, SyncFailure, SyncQueue, UpcomingPanelPayoutStore, UpcomingPayments,
    UpcomingPayoutAdapter, UpcomingPayoutSource, UpcomingPayoutStore};

function check(bool $value,string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$channels = ['Hepsiburada','n11','Pazarama','idefix'];
$today = '2026-11-29';
$pending = [
    'id'=>1,'channel'=>'Hepsiburada','expectedDate'=>$today,'expectedCents'=>10000,
    'status'=>'pending','verification'=>'confirmed','verificationSource'=>'Satıcı paneli',
];
$paid = [
    ...$pending,'id'=>8,'status'=>'paid','actualCents'=>12500,'paidDate'=>'2026-11-02',
    'paymentApproval'=>['at'=>'2026-11-02T09:00:00Z','userId'=>1],
];
$payments = [
    ...array_map(static fn(int $day): array => [...$pending, 'id'=>$day+1,'expectedDate'=>"2026-12-0$day"],range(1,5)),
    $pending,
    $paid,
    [...$pending,'id'=>15,'channel'=>'n11','expectedDate'=>'2026-12-28'],
    [...$pending,'id'=>29,'channel'=>'Pazarama','expectedDate'=>'2026-11-30','expectedCents'=>17500],
    [...$pending,'id'=>30,'channel'=>'Pazarama','expectedDate'=>'2026-12-01','expectedCents'=>8000],
    [...$pending,'id'=>16,'verification'=>'estimated'],
    [...$pending,'id'=>17,'verificationSource'=>' '],
    [...$pending,'id'=>18,'status'=>'sent'],
    [...$pending,'id'=>19,'expectedDate'=>'2026-11-28'],
    [...$pending,'id'=>20,'integration'=>'hepsiburada'],
    [...$pending,'id'=>21,'archivedAt'=>'2026-11-28T08:00:00Z'],
    [...$pending,'id'=>22,'paymentStateUnknown'=>true],
    [...$pending,'id'=>23,'excludedFromTotals'=>true],
    [...$pending,'id'=>24,'channel'=>'Koçtaş'],
    [...$pending,'id'=>25,'expectedDate'=>'2026-11-31'],
    [...$pending,'id'=>26,'expectedCents'=>0],
    [...$paid,'id'=>27,'paidDate'=>'2026-10-31'],
    [...$paid,'id'=>28,'paymentApproval'=>null],
];
$report=UpcomingPayments::report($payments,$channels,$today);
check(array_column($report['items'],'id')===[1,29,2,30,3,4,15],
    'Aynı gün teyitli kayıt varken tahmin çift sayılmaz; pazaryeri başına dört kayıt tarih sırasıyla gösterilir.');
check($report['remainingCents']===75500 && $report['confirmedCents']===75500 && $report['estimatedCents']===0
    && $report['paidThisMonthCents']===12500,'Listelenen tahmin, teyit ve banka onayı ayrı toplanır.');
check($report['channelsWithoutData']===['idefix'],'Teyitli ödemesi olan bir kanal verisiz gösterilmez.');
check(array_column(UpcomingPayments::report($payments,['Hepsiburada'],$today)['items'],'id')===[1,2,3,4],
    'Pazaryeri filtresinde de aynı kanalın en yakın dört kaydı gösterilir.');
check(array_column(UpcomingPayments::report($payments,['n11'],$today)['items'],'id')===[15],
    'Dörtten az teyitli ödemesi olan pazaryerinde yalnız kendi kayıtları gösterilir.');
$estimate=[...$pending,'id'=>31,'channel'=>'Pazarama','verification'=>'estimated','expectedDate'=>'2026-11-30'];
$panel=['id'=>'pazarama:2026-11-30','channel'=>'Pazarama','date'=>'2026-11-30','amountCents'=>14000,
    'verification'=>'estimated','source'=>'marketplace_panel'];
$estimateReport=UpcomingPayments::report([$estimate],$channels,$today);
check($estimateReport['estimatedCents']===10000 && $estimateReport['confirmedCents']===0
    && $estimateReport['items'][0]['source']==='manual','Manuel tahmin kendi durumu ve kaynağıyla görünür.');
$panelReport=UpcomingPayments::report([$estimate],$channels,$today,[$panel,$panel]);
check($panelReport['estimatedCents']===14000 && count($panelReport['items'])===1
    && $panelReport['items'][0]['source']==='marketplace_panel','Aynı güne düşen panel ve manuel tahmin birlikte sayılmaz.');
$officialConfirmed=[...$panel,'source'=>'marketplace','verification'=>'confirmed','amountCents'=>16000];
check(UpcomingPayments::report([$estimate],$channels,$today,[$panel,$officialConfirmed])['confirmedCents']===16000,
    'Pazaryeri teyidi aynı günün tahmininden önce gelir.');
$entered=[...$estimate,'source'=>Omnigo\UpcomingManualPayout::SOURCE];
$live=[...$panel,'source'=>'marketplace','amountCents'=>17000,'observedAt'=>time()];
check(UpcomingPayments::snapshot([$entered],$channels,$today,[$live])['items'][0]['amountCents']===17000,
    'Elle girilen tahmin aynı günün güncel pazaryeri okumasını gölgelememeli.');
check(UpcomingPayments::snapshot([$entered],$channels,$today)['items'][0]['manualEntry'],
    'Pazaryeri bu güne ödeme iletmezse manuel tahmin saklanmalı.');
check(UpcomingPayments::report([[...$estimate,'status'=>'sent']],$channels,$today,[$panel])['remainingCents']===0,
    'Aktarımı bildirilen kayıt için panel tahmini bekleyen toplamı şişirmez.');
check(UpcomingPayments::report([$estimate],$channels,$today,[[...$panel,'date'=>'2026-11-31']])['estimatedCents']===10000,
    'Geçersiz panel tarihi manuel kaydı değiştirmez.');
$inside=[...$estimate,'id'=>32,'expectedDate'=>'2026-12-01'];
$outside=[...$estimate,'id'=>33,'expectedDate'=>'2026-12-03'];
$confirmedInside=[...$inside,'id'=>34,'expectedDate'=>'2026-12-02','verification'=>'confirmed'];
$coverage=UpcomingPayments::report([$estimate,$inside,$outside,$confirmedInside],$channels,$today,[$panel],['Pazarama'=>'2026-12-02']);
check(array_column($coverage['items'],'id')===[$panel['id'],34,33]
    && $coverage['estimatedCents']===24000 && $coverage['confirmedCents']===10000,
    'Güncel panel ufkunda eski tahmin gizlenir, ufuk dışı tahmin ve manuel teyit kalır.');

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));
$repo=new Repository($db);
$company=$repo->createCompany('Örnek', ['n11']);
$another=$repo->createCompany('Başka', ['n11']);
$before=date('Y-m-d',strtotime('+1 day'));
$dated=[...$pending,'channel'=>'n11','expectedDate'=>$before];
foreach([$company,$another] as $tenant){
    $entry = [...$dated,'expectedCents' => 20000*$tenant];
    $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",
        [$tenant,json_encode($entry,JSON_THROW_ON_ERROR),gmdate('c')]);
}
$calendar=new PaymentCalendar($db);
$admin=$calendar->report(['role'=>'admin'],$company,'2026-01-01','2026-01-31','n11',true);
$client=$calendar->report(['role'=>'client','company_id'=>$company],$company,'2026-01-01','2026-01-31','n11',true);
check(count($admin['upcoming']['items'])===1 && $admin['upcoming']['items'][0]['amountCents']===20000*$company,
    'Gelecek teyitli tutar ay filtresinden bağımsız ve seçili müşteriye özeldir.');
check(!array_key_exists('upcoming',$client),'Müşteri yanıtında yeni yönetici özeti bulunmaz.');
check($admin['items']===$client['items'] && $admin['summary']===$client['summary'],'Mevcut ay ve özet değişmez.');

FinanceSchema::install($db);
$pilot=$repo->createCompany('VİZYONA', ['n11','Koçtaş','Hepsiburada','Pazarama']);
$panelPath=tempnam(sys_get_temp_dir(),'upcoming-panel');
try {
    file_put_contents($panelPath,json_encode([
        'version'=>1,'companyId'=>$pilot,'companyName'=>'VİZYONA','channels'=>[
            'Hepsiburada'=>['observedDate'=>'2026-11-14','payments'=>[
                '2026-11-17'=>30273594,'2026-12-08'=>2678999,'2026-12-15'=>18534583,'2026-12-22'=>53787407,
            ]],
            'PTT AVM'=>['observedDate'=>'2026-11-14','payments'=>['2026-11-19'=>21687549,'2026-11-26'=>6481497]],
        ],
    ],JSON_THROW_ON_ERROR));
    $panelStore=new UpcomingPanelPayoutStore($db,$panelPath);
    $panelSnapshot=$panelStore->snapshot($pilot,['Hepsiburada','PTT AVM'],'2026-11-14');
    check(count($panelSnapshot['rows'])===6 && $panelSnapshot['coverage']===['Hepsiburada'=>'2026-12-22','PTT AVM'=>'2026-11-26'],
        'Her panel kendi ödeme tarihlerini ve kapsamını verir.');
    $ptt=$panelStore->snapshot($pilot,['PTT AVM'],'2026-11-20');
    check(count($ptt['rows'])===1 && $ptt['rows'][0]['amountCents']===6481497 && $ptt['rows'][0]['observedDate']==='2026-11-14',
        'Geçmiş ödeme ve seçilmeyen pazaryeri filtrelenir, kaynak tarihi korunur.');
    check(!$panelStore->snapshot($company,['Hepsiburada','PTT AVM'],'2026-11-14')['rows'],
        'Panel kayıtları diğer mağazalara açılmaz.');
} finally { unlink($panelPath); }
$key=tempnam(sys_get_temp_dir(),'upcoming-key');
file_put_contents($key,random_bytes(32));
try {
    $vault=new IntegrationVault($db,$key);
    $settings=['apiKey'=>'test','apiSecret'=>'test','enabled'=>true];
    $vault->save($pilot,$settings,'n11');
    $now=new DateTimeImmutable('today',new DateTimeZone('Europe/Istanbul'));
    $day=$now->modify('+2 days')->format('Y-m-d');
    $sampleDate=$now->modify('-12 days')->format('Y-m-d');
    $sample=['settlementDate'=>$sampleDate,'remittanceDate'=>$day,'paymentAmount'=>'120',
        'deductionAmount'=>'20','settlementAmount'=>'100','status'=>'Wait_Remittance'];
    $rows=[
        $sample,
        [...$sample,'remittanceDate'=>'','status'=>'Confirmed'],
        [...$sample,'status'=>'NotConfirmed'],
        [...$sample,'status'=>'Success'],
    ];
    $failure=false;
    $duplicate=false;
    $reader=new N11FinanceClient(static function($url,$request)use($rows,$sampleDate,&$failure,&$duplicate):array {
        if($failure)return ['status'=>429,'retryAfter'=>900];
        $doc=new DOMDocument();
        $doc->loadXML($request);
        $xp=new DOMXPath($doc);
        $from=$xp->evaluate('string(//*[local-name()="startDate"])');
        $through=$xp->evaluate('string(//*[local-name()="endDate"])');
        $page=$xp->evaluate('string(//*[local-name()="currentPage"])');
        check($url==='https://api.n11.com/ws/settlementService/'&&str_contains($request,'GetSettlementListRequest')
            &&(new DateTimeImmutable($from))->diff(new DateTimeImmutable($through))->days<=29,'Resmi ve sınırlı hakediş sorgusu.');
        $list=$from<=$sampleDate&&$through>=$sampleDate?$rows:[];
        if($list&&$duplicate)$list[]=$list[0];
        $count=count($list);
        $pages=$count?1:0;
        $xml="<Envelope><Body><GetSettlementListResponse><result><status>success</status></result><settlementListData>
            <metadata><currentPage>$page</currentPage><pageSize>100</pageSize><totalCount>$count</totalCount><pageCount>$pages</pageCount></metadata>";
        foreach($list as $row){
            $xml.='<settlementList>';
            foreach($row as $field=>$value)$xml.="<{$field}>{$value}</{$field}>";
            $xml.='</settlementList>';
        }
        return ['status'=>200,'body'=>$xml.'</settlementListData></GetSettlementListResponse></Body></Envelope>'];
    });
    $adapter=new UpcomingPayoutAdapter($db,$vault,$reader);
    $upcoming=new UpcomingPayoutStore($db);
    $scanDay=$now->format('Y-m-d');
    $adapter->syncReadOnly($pilot,time()+20);
    check(!$upcoming->rows($pilot,['n11'],$scanDay),'Eksik tarih taraması yayına alınmaz.');
    $duplicate=true;
    try {
        for($page=0;$page<6&&$adapter->nextDelay()===60;$page++) $adapter->syncReadOnly($pilot,time()+20);
        $adapter->syncReadOnly($pilot,time()+20);
        throw new RuntimeException('Mükerrer kaynak kabul edildi.');
    } catch (SyncFailure $error) { check($error->httpStatus===409,'Mükerrer kaynak taramayı yeniden başlatır.'); }
    $duplicate=false;
    for ($page=0;$page<6&&$adapter->nextDelay()===60;$page++) $adapter->syncReadOnly($pilot,time()+20);
    $published=$upcoming->rows($pilot,['n11'],$scanDay);
    check(count($published)===1&&$published[0]['date']===$day&&$published[0]['amountCents']===10000,
        'Yalnız tarihli ve teyitli bekleyen net ödeme gösterilir.');
    $failure=true;
    try {
        $adapter->syncReadOnly($pilot,time()+20);
        throw new RuntimeException('Kota hatası bekleniyordu.');
    } catch (SyncFailure $error) {
        check($error->httpStatus===429&&$error->retryAfter===900,'Kaynak tekrar aralığı korunur.');
    }
    check($upcoming->rows($pilot,['n11'],$scanDay)===$published
        &&!$upcoming->rows($pilot,['n11'],$scanDay,time()+3601),'Güncel son tam aktarım korunur ve eskidiğinde gizlenir.');
    check(!$upcoming->rows($company,['n11'],$scanDay)&&!$upcoming->rows($pilot,['Koçtaş'],$scanDay),'Mağaza ve pazaryeri ayrımı.');
    $manual=[...$pending,'channel'=>'n11','expectedDate'=>$day,'expectedCents'=>9000];
    check(UpcomingPayments::report([$manual],['n11'],$scanDay,$published)['remainingCents']===9000,
        'Bir tarih için teyitli manuel ödeme ve API kaydı çift sayılmaz.');
    check(UpcomingPayments::report([[...$manual,'verification'=>'estimated']],['n11'],$scanDay,$published)['remainingCents']===10000,
        'Teyitli resmi kayıt tahmini manuel kayıt nedeniyle kaybolmaz.');
    $admin=$calendar->report(['role'=>'admin'],$pilot,$now->format('Y-m-01'),$now->modify('last day of this month')->format('Y-m-d'),'all',true);
    $client=$calendar->report(['role'=>'client','company_id'=>$pilot],$pilot,$now->format('Y-m-01'),$now->modify('last day of this month')->format('Y-m-d'),'all',true);
    check($admin['upcoming']['remainingCents']===10000&&$admin['upcoming']['paidThisMonthCents']===0
        &&!array_key_exists('upcoming',$client)&&!$client['items']&&!$admin['items']&&$admin['summary']===$client['summary'],
        'Resmi gelecek ödemeler yalnız yönetici özetinde gösterilir; manuel takvim veya banka onayı oluşmaz.');
    $vault->save($pilot,$settings,'n11');
    check(!$upcoming->rows($pilot,['n11'],$scanDay),'Bağlantı değişikliği önceki aktarımı geçersiz kılar.');
    check(!(new FinanceStore($db))->state($pilot,'n11'),'Eski hakediş arşivine yazılmaz.');

    $merchant='00000000-0000-0000-0000-000000000010';
    $hbConfig=['merchantId'=>$merchant,'username'=>$merchant,'serviceKey'=>'test',
        'userAgent'=>'example_dev','enabled'=>true];
    $vault->save($pilot,$hbConfig,'hepsiburada');
    $tuesday=$now->modify('+'.((9-(int)$now->format('N'))%7).' days');
    $payDay=$tuesday->format('Y-m-d');
    $dueDay=$tuesday->modify('-6 days')->format('Y-m-d');
    $hbRow=['merchantId'=>$merchant,'transactionType'=>'Payment','status'=>'WillBePaid',
        'dueDate'=>$dueDay.'T00:00:00','paymentDate'=>null,'isIncome'=>true,'amount'=>['value'=>10,'currencyCode'=>'TRY']];
    $deduction=[...$hbRow,'dueDate'=>$payDay.'T00:00:00','id'=>'commission','transactionType'=>'Commission',
        'isIncome'=>false,'amount'=>['value'=>-100,'currencyCode'=>'TRY']];
    $paidTransfer=[...$deduction,'id'=>'paid','status'=>'Paid','transactionType'=>'TotalPayment','paymentDate'=>$payDay.'T00:00:00'];
    $hbRows = [...array_map(static fn(int $index): array => [...$hbRow,'id'=>'income-'.$index],range(1,100)),
        $deduction,$paidTransfer];
    $failAtOffset = true;
    $hbReader = new FinanceClient(static function(string $method,string $url) use ($hbRows,&$failAtOffset,$dueDay): array {
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        check($method==='GET' && str_starts_with($url,'https://mpfinance-external.hepsiburada.com/transactions/merchantid/'),
            'Hepsiburada tahmini resmi finans okumasından alınır.');
        $from=$query['DueDateStart'];
        $to=$query['DueDateEnd'];
        $offset=(int)$query['Offset'];
        check($offset!==0 || $from>=$dueDay,'Taramaya ilk açık ödemenin haftasından başlanır.');
        if ($offset>0 && $failAtOffset) return ['status'=>429,'retryAfter'=>900];
        $window=array_values(array_filter($hbRows,static function (array $row) use ($from,$to): bool {
            $date=substr($row['dueDate'],0,10);
            return $date >= $from && $date <= $to;
        }));
        return ['status'=>200,'body'=>json_encode(['count'=>count($window),'items'=>array_slice($window,$offset,100)])];
    });
    $hb = new UpcomingPayoutAdapter($db,$vault,provider:'hepsiburada',finance:$hbReader);
    $jobName=UpcomingPayoutAdapter::JOBS['hepsiburada'];
    $queue=new SyncQueue($db,[$jobName=>$hb]);
    $queue->enqueue($pilot,$jobName);
    check($queue->runOne(null,[$jobName],time()+20) && !$upcoming->rows($pilot,['Hepsiburada'],$scanDay),
        'İlk sayfa tek başına hakedişi değiştirmez.');
    $job = fn(): array => $db->one('SELECT next_run,error,attempts FROM jobs WHERE company_id=? AND provider=?',[$pilot,$jobName]);
    check($queue->runOne((int)$job()['next_run'],[$jobName],time()+20) && $job()['error'] !== null
        && (int)$job()['next_run'] >= time()+900 && !$upcoming->rows($pilot,['Hepsiburada'],$scanDay),
        'Kesilen sayfa tekrar kuyruğa alınır ve kaynak kota süresi korunur.');
    $failAtOffset = false;
    for ($page=0;$page<5 && $hb->nextDelay()===60;$page++) {
        check($queue->runOne((int)$job()['next_run'],[$jobName],time()+20),'Vadesi gelen sayfa işlenir.');
    }
    $hbPublished=$upcoming->rows($pilot,['Hepsiburada'],$scanDay);
    check(count($hbPublished)===1 && $hbPublished[0]['date']===$payDay && $hbPublished[0]['amountCents']===90000
        && $hbPublished[0]['verification']==='estimated','İmzalı hareketler aynı salıda toplanır; kesinti düşer ve eski transfer eklenmez.');
    check(!(new FinanceStore($db))->state($pilot,'hepsiburada') && !$upcoming->rows($company,['Hepsiburada'],$scanDay),
        'Farklı müşteri ile manuel finans arşivi bütünüyle ayrıdır.');
    $vault->save($pilot,$hbConfig,'hepsiburada');
    check(!$upcoming->rows($pilot,['Hepsiburada'],$scanDay),'Eski bağlantının taslağı yeni bağlantıda görünmez.');

    $seller='00000000-0000-0000-0000-000000000011';
    $pzConfig=['sellerId'=>$seller,'apiKey'=>'test','apiSecret'=>'test-secret','enabled'=>true];
    $vault->save($pilot,$pzConfig,'pazarama');
    $payload=base64_encode(json_encode(['client_seller_id'=>$seller]));
    $pzAuth=new PazaramaAuthClient(static fn(): array=>['status'=>200,
        'body'=>json_encode(['access_token'=>'test.'.$payload.'.test','token_type'=>'Bearer','expires_in'=>600])]);
    $pzDate=$now->modify('+8 days')->format('Y-m-d');
    $pzRow=['trxId'=>'00000000-0000-0000-0000-000000000012','transactionDate'=>$dueDay.'T00:00:00',
        'transferredDate'=>$pzDate.'T00:00:00','allowanceAmount'=>1000,'status'=>'Satış'];
    $pzBadDate=false;
    $pzReader=new FinanceClient(static function (string $method,string $url,array $headers,?string $json)
        use ($pzRow,$pzDate,&$pzBadDate): array {
        $query=json_decode($json,true);
        check($method==='POST' && str_ends_with($url,'/order/paymentAgreement')
            && $query['startDate']===null && $query['endDate']===null
            && is_string($query['allowanceStartDate']) && is_string($query['allowanceEndDate']),
            'Pazarama sipariş tarihi yerine gerçek hakediş filtresiyle sorgulanır.');
        $from=substr($query['allowanceStartDate'],0,10);
        $to=substr($query['allowanceEndDate'],0,10);
        check((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days<=13,'Hakediş okuması 14 günlük pencere kullanır.');
        $rows=$from<=$pzDate && $pzDate<=$to ? [
            $pzBadDate ? [...$pzRow,'transferredDate'=>null] : $pzRow,
            [...$pzRow,'allowanceAmount'=>-125,'status'=>'İade'],
            [...$pzRow,'allowanceAmount'=>2000,'status'=>'Hakediş satıcıya gönderildi'],
        ] : [];
        return ['status'=>200,'body'=>json_encode(['success'=>true,'data'=>['transactionList'=>$rows]])];
    });
    $pzAdapter=new UpcomingPayoutAdapter($db,$vault,provider:'pazarama',finance:$pzReader,pazaramaAuth:$pzAuth);
    for ($window=0;$window<4;$window++) $pzAdapter->syncReadOnly($pilot,time()+20);
    $pzPublished=$upcoming->rows($pilot,['Pazarama'],$scanDay);
    check(count($pzPublished)===1 && $pzPublished[0]['date']===$pzDate && $pzPublished[0]['amountCents']===87500
        && $pzPublished[0]['verification']==='estimated','Pazarama yalnız tarihli, aktarılmamış net hareketleri tahmin olarak verir.');
    $pzBadDate=true;
    try {
        $pzAdapter->syncReadOnly($pilot,time()+20);
        throw new RuntimeException('Tarihsiz hakediş için hata bekleniyordu.');
    } catch (SyncFailure $error) {
        check($error->httpStatus===422 && $upcoming->rows($pilot,['Pazarama'],$scanDay)===$pzPublished,
            'Tarih alanı geçersizse son tam ödeme okuması korunur.');
    }

    $vault->save($pilot,['enabled'=>true,'shopId'=>123],'koctas');
    $panelSource = new UpcomingPayoutSource($db,$vault);
    $koctas = $panelSource->configured($pilot)['koctas'];
    check($koctas['identity']==='Vizyona' && !$panelSource->configured($company),'Satıcı paneli bağlantısı VİZYONA’ya bağlıdır.');
    $seenAt=time()-3;
    $capture=[(object)['date'=>$day,'amountCents'=>15000]];
    $savePanel=fn(int $firm,string $seller,string $connection,array $items,int $stamp)=>$panelSource->receive(
        $firm,'koctas',$seller,$connection,$items,$stamp);
    foreach ([
        [$company,'Vizyona',$koctas['connection'],$capture,$seenAt,403],
        [$pilot,'Farklı mağaza',$koctas['connection'],$capture,$seenAt,403],
        [$pilot,'Vizyona','eski',$capture,$seenAt,409],
        [$pilot,'Vizyona',$koctas['connection'],[...$capture,...$capture],$seenAt,422],
        [$pilot,'Vizyona',$koctas['connection'],$capture,$seenAt-360,409],
    ] as [$firm,$sellerName,$revision,$items,$stamp,$status]) {
        try {
            $savePanel($firm,$sellerName,$revision,$items,$stamp);
            throw new RuntimeException('Geçersiz panel verisi kabul edildi.');
        } catch (HttpError $error) {
            check($error->getCode()===$status,'Firma, bağlantı sürümü ve panel kaydı doğrulanır.');
        }
    }
    check($savePanel($pilot,'Vizyona',$koctas['connection'],$capture,$seenAt)===['accepted'=>true,'count'=>1],
        'Tam panel okuması aktarıldı.');
    check($savePanel($pilot,'Vizyona',$koctas['connection'],$capture,$seenAt)===['accepted'=>false,'count'=>1],
        'Aynı okuma yeni bir ödeme oluşturmuyor.');
    $panelRows=$upcoming->rows($pilot,['Koçtaş'],$scanDay);
    check(count($panelRows)===1 && $panelRows[0]['amountCents']===15000 && $panelRows[0]['verification']==='estimated',
        'Panelden otomatik okunan kayıt, tahmini hakediş olarak yayımlanır.');
    $manualKoctas=[...$pending,'channel'=>'Koçtaş','expectedDate'=>$day,'expectedCents'=>9201];
    $zero=[(object)['date'=>$day,'amountCents'=>0]];
    check($savePanel($pilot,'Vizyona',$koctas['connection'],$zero,$seenAt+1)===['accepted'=>true,'count'=>0],
        'Yalnız açıkça sıfır olarak bildirilen gün sıfır olarak saklanır.');
    $panelRows=$upcoming->rows($pilot,['Koçtaş'],$scanDay);
    check(UpcomingPayments::report([],['Koçtaş'],$scanDay,$panelRows)['items']===[]
        && UpcomingPayments::report([$manualKoctas],['Koçtaş'],$scanDay,$panelRows)['confirmedCents']===9201,
        'Sıfır tutar dışa aktarılmaz, teyitli manuel kayıt korunur.');
    $vault->save($pilot,['enabled'=>true,'shopId'=>123],'koctas');
    check(!$upcoming->rows($pilot,['Koçtaş'],$scanDay),'Bağlantı yenilenince önceki panel okuması kabul edilmez.');
} finally { unlink($key); }
echo "Gelecek hakediş sıralaması, kapsam, toplamlar ve mağaza izinleri doğrulandı.\n";
