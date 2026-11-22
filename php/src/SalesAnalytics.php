<?php
declare(strict_types=1);
namespace Omnigo;

/** Range reporting reads local snapshots and never calls a marketplace from an HTTP request. */
final class SalesAnalytics
{
    public function __construct(
        private Database $db,
        private ?UndeliveredSalesReader $projection=null,
        private ?HistoricalSalesArchive $archive=null
    ) {}
    public function report(array $user,int $company,string $channel,string $from,string $to,int $page=0,int $limit=25,?\DateTimeImmutable $cutoff=null,bool $historyPreset=false): array
    {
        Validation::date($from);Validation::date($to);
        $zone=new \DateTimeZone('Europe/Istanbul');
        $start=new \DateTimeImmutable($from.' 00:00:00',$zone);$end=new \DateTimeImmutable($to.' 23:59:59.999999',$zone);
        if ($from>$to || ($start->diff($end)->days>365&&(!$historyPreset||$from!==ReportPeriod::HISTORY_START)) || $page<0 || $page>2000 || $limit<1 || $limit>5000) Http::fail(400,'En fazla bir yıllık geçerli bir tarih aralığı seçin.');
        if ($cutoff!==null && $cutoff<$end) $end=$cutoff;
        $channels=CustomerMarketplaces::reportingChannels($this->db,$company,(new Repository($this->db))->records($company,'channels',$user));
        $matched=array_values(array_filter($channels,fn($row)=>$row['name']===$channel&&($user['role']==='admin'||$row['status']==='active'||!empty($row['historical']))));
        if (!$matched) Http::fail(404,'Pazaryeri bulunamadı.');
        $base=['channel'=>$channel,'from'=>$from,'to'=>$to,'available'=>false,'products'=>[],'topProducts'=>[],'page'=>$page,'hasMore'=>false];
        $provider=MarketplaceSalesSummary::PROVIDERS[$channel]??null;
        if ($provider===null) return [...$base,'message'=>'Bu pazaryerinin günlük satış ve ürün aktarımı henüz hazır değil.'];
        $customerView=$user['role']==='client';
        $read=function() use($company,$base,$start,$end,$from,$to,$page,$limit,$provider,$customerView) {
            $sync=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
            $state=$sync?json_decode($sync['payload'],true,32,JSON_THROW_ON_ERROR):[];
            if($provider==='hepsiburada')$state=HepsiburadaSalesOrders::reportingState($state);
            $hasLive=!empty($state['watermark'])&&!empty($state['startDate'])&&!isset($state['scanEnd']);
            $liveStart=$hasLive?new \DateTimeImmutable($state['startDate']):null;
            $liveEnd=$hasLive?new \DateTimeImmutable($state['watermark']):null;
            $archive=$this->archive?->snapshot($company,$provider,$start,$end);
            $useLive=$hasLive&&$liveStart<=$end&&$liveEnd>=$start;
            $useArchive=$archive!==null&&(!$useLive||$liveStart>$archive['from']);
            if (!$useLive&&!$useArchive) {
                if (isset($state['scanEnd'])) return [...$base,'message'=>'Satış aktarımı tamamlanıyor. Bir sonraki kontrolde tekrar deneyebilirsiniz.'];
                if (!$hasLive) return [...$base,'message'=>'İlk satış aktarımı bekleniyor.'];
                return [...$base,'message'=>'Seçilen tarihler için aktarılmış sipariş verisi bulunmuyor.','coverageFrom'=>SalesCalendar::day($state['startDate']),'asOf'=>$state['watermark']];
            }
            $coverageStart=$useArchive?$archive['from']:$liveStart;
            $archiveEnd=$useArchive?$archive['untilExclusive']->modify('-1 microsecond'):null;
            $coverageEnd=$useLive?$liveEnd:$archiveEnd;
            $separateCoverage=$useLive&&$useArchive&&$liveStart>$archive['untilExclusive'];
            $archiveLimit=$useLive&&$useArchive?min($liveStart,$archive['untilExclusive']):($archive['untilExclusive']??null);
            $unverifiedDays=[];
            if($useArchive) {
                foreach($archive['unverifiedOrderTimes']??[] as $at) {
                    $time=new \DateTimeImmutable($at);
                    if($time>=$start&&$time<=$end&&$time<$archiveLimit)$unverifiedDays[SalesCalendar::day($at)]=true;
                }
            }
            $refundsVerified=(!$useLive||$provider!=='idefix'||($state['refundsVerified']??false)===true)
                &&(!$useArchive||$archive['refundsVerified']===true);
            $totals=[...SalesCalendar::ZERO,...SourceSalesAmounts::ZERO,...TurnoverAmounts::ZERO,'salesOrders'=>0,'orderedUnits'=>0,'soldUnits'=>0];$products=[];$missing=0;$unknownQuantities=0;$days=[];
            $entries=$this->reportOrders($company,$provider,$useLive?$liveStart:null,$useArchive?$archive['orders']:[],$archiveLimit);
            foreach ($entries as $entry) {
                $order=$entry['order'];$sourceAmounts=SourceSalesAmounts::order($provider,$entry['source']);
                $created=new \DateTimeImmutable($order['createdAt']);
                if ($created<$start||$created>$end||$created>$coverageEnd||$created<$coverageStart||$order['orders']===0) continue;
                SalesCalendar::add($totals,$order);SourceSalesAmounts::add($totals,$sourceAmounts);
                $turnover=TurnoverAmounts::order($provider,$entry['source']);TurnoverAmounts::add($totals,$turnover);
                $day=SalesCalendar::day($order['createdAt']);$days[$day]??=[...SalesCalendar::ZERO,...SourceSalesAmounts::ZERO,...TurnoverAmounts::ZERO,'salesOrders'=>0];SalesCalendar::add($days[$day],$order);SourceSalesAmounts::add($days[$day],$sourceAmounts);TurnoverAmounts::add($days[$day],$turnover);
                $salesOrders=TurnoverAmounts::activeOrders($entry['source']);
                $totals['salesOrders']+=$salesOrders;$days[$day]['salesOrders']+=$salesOrders;
                $units=$customerView?SalesUnitTotals::activeOrder($entry['source']):SalesUnitTotals::quantity($entry['source'],'quantity');
                $totals['orderedUnits']=SalesUnitTotals::add($totals['orderedUnits'],$units);
                $totals['soldUnits']=SalesUnitTotals::add($totals['soldUnits'],$customerView?$units:SalesUnitTotals::quantity($order,'netQuantity'));
                if (!isset($order['products'])) {$missing++;continue;}
                foreach ($order['products'] as $index=>$product) {
                    $key=hash('sha256',($product['sku']!==''?'sku:'.$product['sku']:'name:'.$product['title']));
                    if (!isset($products[$key])) $products[$key]=['sku'=>$product['sku'],'title'=>$product['title'],'imageUrl'=>KoctasProductMapper::imageUrl($product['imageUrl']??null),'quantity'=>0,'netQuantity'=>0,'grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'netCents'=>0,...SourceSalesAmounts::ZERO];
                    $sourceProduct=$entry['source']['products'][$index];
                    if($customerView)$product['quantity']=$product['netQuantity']=SalesUnitTotals::activeLine($sourceProduct);
                    $products[$key]+=TurnoverAmounts::ZERO;TurnoverAmounts::add($products[$key],TurnoverAmounts::order($provider,['grossCents'=>$sourceProduct['grossCents'],'cancelCents'=>$sourceProduct['cancelCents'],'products'=>[$sourceProduct]]));
                    SourceSalesAmounts::add($products[$key],SourceSalesAmounts::order($provider,['grossCents'=>$sourceProduct['grossCents'],'cancelCents'=>$sourceProduct['cancelCents'],'products'=>[$sourceProduct]]));
                    foreach (['grossCents','cancelCents','returnCents','netCents'] as $field) $products[$key][$field]+=$product[$field];
                    foreach (['quantity','netQuantity'] as $field) $products[$key][$field]=$products[$key][$field]===null||$product[$field]===null?null:$products[$key][$field]+$product[$field];
                    if (!$products[$key]['imageUrl']) $products[$key]['imageUrl']=KoctasProductMapper::imageUrl($product['imageUrl']??null);
                }
            }
            $products=(new SalesProductImages($this->db))->attach($company,$provider,array_values($products));
            $units=$customerView?'quantity':'netQuantity';
            $amount=$customerView?'salesTurnoverCents':'netCents';
            if($customerView)$products=array_values(array_filter($products,static fn(array $row):bool=>$row['quantity']!==0));
            usort($products,fn($a,$b)=>(($b[$units]??-1)<=>($a[$units]??-1))?:($b[$amount]<=>$a[$amount])?:strcmp($a['sku'].$a['title'],$b['sku'].$b['title']));
            $unknownQuantities=count(array_filter($products,fn($row)=>$row[$units]===null));
            $top=$missing?[]:array_slice(array_values(array_filter($products,fn($row)=>($row[$units]??0)>0)),0,10);
            $totals['netCents']=$totals['grossCents']-$totals['cancelCents']-$totals['returnCents'];
            return [...$base,'available'=>true,'panelBasis'=>['idefix'=>'before_discounts','pazarama'=>'buyer_payment'][$provider]??'invoice','refundsVerified'=>$refundsVerified,'totals'=>$totals,'products'=>array_slice($products,$page*$limit,$limit),'topProducts'=>$top,'productCount'=>count($products),'hasMore'=>count($products)>($page+1)*$limit,'days'=>$days,
                'missingProductOrders'=>$missing,'unknownQuantities'=>$unknownQuantities,'partialHistory'=>$coverageStart>$start||$separateCoverage||$unverifiedDays!==[],
                ...($unverifiedDays?['unverifiedDays'=>array_keys($unverifiedDays)]:[]),
                ...($separateCoverage?['coverageGap'=>['from'=>$archive['untilExclusive']->format(DATE_ATOM),'until'=>$liveStart->format(DATE_ATOM)]]:[]),
                'coverageStart'=>$useArchive?$coverageStart->format(DATE_ATOM):$state['startDate'],
                'coverageFrom'=>$coverageStart->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d'),
                'asOf'=>$useLive?$state['watermark']:$coverageEnd->format('Y-m-d\TH:i:s.uP')];
        };
        return $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
    }

    private function reportOrders(int $company,string $provider,?\DateTimeImmutable $liveStart,array $archive,?\DateTimeImmutable $archiveLimit): \Generator
    {
        foreach ($archive as $source) {
            if (new \DateTimeImmutable($source['createdAt']) < $archiveLimit) yield ['source'=>$source,'order'=>$source];
        }
        if ($liveStart === null) return;

        $rows=$provider==='hepsiburada'?(new HepsiburadaSalesOrders($this->db))->rows($company):$this->db->run('SELECT payload FROM records WHERE company_id=? AND kind=? ORDER BY id',[$company,'order_'.$provider]);
        if(in_array($provider,['pttavm','trendyol'],true))$rows=$this->db->run('SELECT payload FROM recent_orders WHERE company_id=? AND provider=? ORDER BY id',[$company,$provider]);
        foreach (($this->projection??=new UndeliveredSalesReader($this->db))->orders($company,$provider,$rows) as $entry) {
            if (new \DateTimeImmutable($entry['order']['createdAt']) >= $liveStart) yield $entry;
        }
    }
}
