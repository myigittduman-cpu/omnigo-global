<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads completed local order snapshots, without API calls or customer details. */
final class MarketplaceSalesSummary
{
    public const PROVIDERS=['Koçtaş'=>'koctas','n11'=>'n11','Pazarama'=>'pazarama','idefix'=>'idefix','Hepsiburada'=>'hepsiburada','PTT AVM'=>'pttavm','Trendyol'=>'trendyol'];
    public function __construct(private Database $db,private ?UndeliveredSalesReader $projection=null){}
    public function dashboard(int $company,array $channels,string $month,?\DateTimeImmutable $clock=null,?array $range=null,bool $includePeriodMetrics=false,bool $customerView=false): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');$clock=($clock??new \DateTimeImmutable('now',$zone))->setTimezone($zone);
        $monthStart=new \DateTimeImmutable($month.'-01 00:00:00',$zone);$monthEnd=min($clock,$monthStart->modify('last day of this month')->setTime(23,59,59));
        if($range!==null){[$monthStart,$requestedEnd]=ReportPeriod::range($range['from'],$range['to']);$monthEnd=min($clock,$requestedEnd);}
        $oldest=$clock->setTime(0,0)->modify('-60 days');$items=[];
        $windows=SalesPeriodMetrics::windows($clock);
        if(!$includePeriodMetrics)$windows=['month'=>$windows['month']];
        $metrics=array_fill_keys(array_column($channels,'name'),[]);
        if($includePeriodMetrics)$oldest=min($oldest,...array_column($windows,0));
        $read=function()use($company,$channels,$clock,$month,$monthStart,$monthEnd,$oldest,$zone,$range,$windows,$customerView,&$metrics,&$items){
            foreach($channels as $channel){
                $provider=self::PROVIDERS[$channel['name']]??null;$base=['channel'=>$channel['name'],'available'=>false,'message'=>'Günlük satış aktarımı bekleniyor.'];
                if(!$provider){$items[]=$base;continue;}
                $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);$state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
                if($provider==='hepsiburada')$state=HepsiburadaSalesOrders::reportingState($state);
                if(empty($state['watermark'])||empty($state['startDate'])||($provider==='idefix'&&empty($state['ordersPublished']))){$items[]=$base;continue;}
                $from=(new \DateTimeImmutable($state['startDate']))->setTimezone($zone);
                $watermark=(new \DateTimeImmutable($state['watermark']))->setTimezone($zone);
                // In-place scan pages must stay out of the report until the scan commits.
                if(isset($state['scanEnd'])){
                    if($provider==='koctas')$metrics[$channel['name']]=(new KoctasCompletedPeriodMetrics($this->db))->read($company,$windows,$from,$watermark,$clock);
                    $items[]=[...$base,'message'=>'Satış aktarımı tamamlanıyor.'];
                    continue;
                }
                $through=min($clock,$watermark);
                $historyStart=$provider==='n11'?$oldest->modify('-4 days'):$oldest;
                $date=$this->db->mysql()?"SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.createdAt')),1,10)":"SUBSTR(JSON_EXTRACT(payload,'$.createdAt'),1,10)";
                $rows=$provider==='hepsiburada'?(new HepsiburadaSalesOrders($this->db))->rows($company):$this->db->run("SELECT payload FROM records WHERE company_id=? AND kind=? AND (($date BETWEEN ? AND ?) OR ($date BETWEEN ? AND ?))",[$company,'order_'.$provider,$historyStart->modify('-1 day')->format('Y-m-d'),$clock->modify('+1 day')->format('Y-m-d'),$monthStart->modify('-1 day')->format('Y-m-d'),$monthEnd->modify('+1 day')->format('Y-m-d')]);
                if(in_array($provider,['pttavm','trendyol'],true))$rows=$this->db->run("SELECT payload FROM recent_orders WHERE company_id=? AND provider=? AND (($date BETWEEN ? AND ?) OR ($date BETWEEN ? AND ?))",[$company,$provider,$historyStart->modify('-1 day')->format('Y-m-d'),$clock->modify('+1 day')->format('Y-m-d'),$monthStart->modify('-1 day')->format('Y-m-d'),$monthEnd->modify('+1 day')->format('Y-m-d')]);
                $orders=[];
                foreach(($this->projection??=new UndeliveredSalesReader($this->db))->orders($company,$provider,$rows) as $entry){
                    $order=$entry['order'];if(($order['orders']??0)<=0)continue;
                    $quantity=isset($order['products'])?0:null;
                    foreach($order['products']??[] as $product)$quantity=$quantity===null||($product['netQuantity']??null)===null?null:$quantity+$product['netQuantity'];
                    $salesQuantity=isset($order['products'])?0:null;
                    foreach($order['products']??[] as $p){
                        $count=isset($p['cancelledQuantity'])?($p['quantity']===null?null:$p['quantity']-$p['cancelledQuantity']):($p['cancelCents']===0?$p['quantity']:($p['cancelCents']===$p['grossCents']?0:($p['returnCents']===0?$p['netQuantity']:null)));
                        $salesQuantity=$salesQuantity===null||$count===null?null:$salesQuantity+$count;
                    }
                    if($customerView)$salesQuantity=SalesUnitTotals::activeOrder($entry['source']);
                    $orders[]=['at'=>new \DateTimeImmutable($order['createdAt']),'quantity'=>$quantity,'salesQuantity'=>$salesQuantity,
                        'orderedQuantity'=>SalesUnitTotals::quantity($entry['source'],'quantity'),
                        'salesOrders'=>TurnoverAmounts::activeOrders($entry['source']),'operationalCancelCents'=>$order['operationalCancelCents']??0,...SourceSalesAmounts::order($provider,$entry['source']),...TurnoverAmounts::order($provider,$entry['source']),...array_intersect_key($order,SalesCalendar::ZERO)];
                }
                $periods=[];foreach(['today'=>1,'seven'=>7,'thirty'=>30] as $key=>$days)$periods[$key]=SalesSummaryPeriods::rolling($orders,$days,$clock,$from,$through);
                if($provider==='hepsiburada')foreach(['seven'=>7,'thirty'=>30] as $key=>$days)$periods[$key]=SalesSummaryPeriods::completed($orders,$days,$clock,$from,$through);
                if($provider==='n11')foreach(['seven','thirty'] as $key)$periods[$key]=N11SummaryPeriods::window($orders,$key,$clock,$from,$through);
                $periods['month']=SalesSummaryPeriods::window($orders,$monthStart,$monthEnd,$from,$through);
                $chartEnd=$provider==='hepsiburada'?$clock->setTime(0,0)->modify('-1 second'):$clock;
                $chartStart=$chartEnd->setTime(0,0)->modify('-29 days');$chartLabel='Son 30 gün';
                if($provider==='n11'){$chartStart=new \DateTimeImmutable($periods['thirty']['from'].' 00:00:00',$zone);$chartLabel='Son 1 aylık satış';}
                if($range!==null||$month!==$clock->format('Y-m')){$chartStart=$monthStart;$chartEnd=$monthEnd;$chartLabel=$range!==null?'Seçili tarih aralığı':'Seçili ay';}
                $chart=[];for($day=$chartStart;$day<=$chartEnd;$day=$day->modify('+1 day')){
                    $value=SalesSummaryPeriods::window($orders,$day,min($clock,$day->setTime(23,59,59)),$from,$through);
                    $known=$value['available']&&!$value['partial'];$entry=['date'=>$day->format('Y-m-d')];
                    foreach([...array_keys(TurnoverAmounts::ZERO),'amountCents','salesCents','panelSalesCents','platformDiscountCents','sellerDiscountCents','grossCents','cancelCents','returnCents','quantity','salesQuantity','orderedQuantity'] as $key)$entry[$key]=$known?$value[$key]:null;
                    $chart[]=$entry;
                }
                $refundsVerified=$provider!=='idefix'||($state['refundsVerified']??false)===true;
                $metrics[$channel['name']]=SalesPeriodMetrics::marketplace($orders,$windows,$from,$through,$refundsVerified,$customerView);
                // PTT return/net figures come from validated order statuses, not bank repayment confirmation.
                $items[]=['channel'=>$channel['name'],'selectedRange'=>$range,'available'=>true,'displayBasis'=>'sales_before_returns','panelBasis'=>['idefix'=>'before_discounts','pazarama'=>'buyer_payment'][$provider]??'invoice','periodPolicy'=>$provider==='n11'?'n11_inclusive':null,'completedPeriods'=>$provider==='hepsiburada','chartLabel'=>$chartLabel,'refundsVerified'=>$refundsVerified,'periods'=>$periods,'days'=>$chart,'coverageFrom'=>$from->format('Y-m-d'),'asOf'=>$through->format(DATE_ATOM)];
            }
        };
        $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
        $summary=SalesPeriodMetrics::summarize($windows,$metrics);
        return ['date'=>$clock->format('Y-m-d'),'channels'=>$items,...($includePeriodMetrics
            ?['periodMetrics'=>$summary]
            :['currentMonthTurnoverCents'=>$summary['all']['month']['turnoverCents']])];
    }
    public static function idefixSale(array $summary,string $month,string $channel='idefix'): ?array
    {
        foreach($summary['channels'] as $item)if($item['channel']===$channel&&!empty($item['periods']['month']['available'])){
            $value=$item['periods']['month'];
            $verified=($item['refundsVerified']??false)===true;
            return ['channel'=>$channel,'month'=>$month,...array_intersect_key($value,SalesCalendar::ZERO),'integration'=>self::PROVIDERS[$channel],'refundsVerified'=>$verified,'source'=>$channel==='Trendyol'?'Trendyol sipariş aktarımı':($channel==='PTT AVM'?'PTT AVM sipariş aktarımı · İndirimler, iptaller ve sipariş durumuyla doğrulanan iadeler düşülür.':($verified?'idefix sipariş ve onaylı iade aktarımı':'idefix sipariş aktarımı · İptaller düşülür; iadeler henüz aktarılmadı.')),'updated'=>$item['asOf']];
        }
        return null;
    }
}
