<?php
declare(strict_types=1);
namespace Omnigo;

/** One consistent local snapshot. Missing coverage remains null, never a fabricated zero. */
final class ReportingService
{
    public function __construct(private Database $db,private ?HistoricalSalesArchive $archive=null) {}
    public function report(array $user,int $company,string $from,string $to,string $channel='all',?\DateTimeImmutable $clock=null): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');$clock=($clock??new \DateTimeImmutable('now',$zone))->setTimezone($zone);
        $today=$clock->format('Y-m-d');
        if($to>$today)Http::fail(400,'Satış raporunun bitiş tarihi bugünden sonra olamaz.');
        $historyPreset=$from===ReportPeriod::HISTORY_START&&$to===$today;
        [$start,$end]=ReportPeriod::range($from,$to,$historyPreset);
        [$previousFrom,$previousTo]=ReportPeriod::previous($from,$to,$historyPreset);
        $longRange=$start->diff($end)->days>365;
        return $this->db->transaction(function()use($user,$company,$from,$to,$channel,$start,$end,$clock,$zone,$previousFrom,$previousTo,$historyPreset,$longRange) {
            $repo=new Repository($this->db);$markets=array_values(array_filter(CustomerMarketplaces::reportingChannels($this->db,$company,$repo->records($company,'channels',$user)),fn($c)=>MarketplaceVisibility::visible($c['name'])&&($user['role']==='admin'||$c['status']==='active'||!empty($c['historical']))&&($channel==='all'||$c['name']===$channel)));
            if($channel!=='all'&&!$markets)Http::fail(404,'Pazaryeri bulunamadı.');
            $series=[];$products=[];$notes=[];$current=[...SalesCalendar::ZERO,...SourceSalesAmounts::ZERO,...TurnoverAmounts::ZERO,'salesOrders'=>0,'orderedUnits'=>0,'soldUnits'=>0];$previous=$current;$available=0;$comparable=0;$refundsVerified=true;
            $analytics=new SalesAnalytics($this->db,archive:$this->archive);
            foreach($markets as $market) {
                $report=$analytics->report($user,$company,$market['name'],$from,$to,0,5000,$clock,$historyPreset);
                if($report['hasMore'])Http::fail(422,'Rapor 5.000 ürünü aşıyor. Daha kısa bir tarih aralığı seçin.');
                $item=['channel'=>$market['name'],'panelBasis'=>$report['panelBasis']??'invoice','available'=>$report['available'],'message'=>$report['message']??'','days'=>[],'asOf'=>$report['asOf']??null,'totals'=>$report['totals']??null,'comparable'=>false];
                if($report['available']) {
                    if(($report['refundsVerified']??true)===false){$refundsVerified=false;$notes[]=$market['name'].': iptaller düşülmüştür, iadeler henüz aktarılmadı. Tutar kesin net satış değildir.';}
                    ++$available;SalesCalendar::add($current,$report['totals']);SourceSalesAmounts::add($current,$report['totals']);TurnoverAmounts::add($current,$report['totals']);
                    $current['salesOrders']+=$report['totals']['salesOrders'];
                    foreach(['orderedUnits','soldUnits'] as $field)$current[$field]=SalesUnitTotals::add($current[$field],$report['totals'][$field]);
                    $coverageStart=new \DateTimeImmutable($report['coverageStart']);$asOf=(new \DateTimeImmutable($report['asOf']))->setTimezone($zone);
                    $effectiveEnd=min($end,$clock,$asOf);
                    $full=!$report['partialHistory']&&$coverageStart<=$start&&$asOf->format('Y-m-d')>=$to;
                    $gapStart=isset($report['coverageGap'])?new \DateTimeImmutable($report['coverageGap']['from']):null;
                    $gapEnd=isset($report['coverageGap'])?new \DateTimeImmutable($report['coverageGap']['until']):null;
                    $unverifiedDays=array_fill_keys($report['unverifiedDays']??[],true);
                    $priorCutoff=new \DateTimeImmutable($previousTo.' '.$effectiveEnd->format('H:i:s'),$zone);
                    $prior=$full&&!$longRange?$analytics->report($user,$company,$market['name'],$previousFrom,$previousTo,0,1,$priorCutoff):null;
                    if($prior&&$prior['available']&&!$prior['partialHistory']&&new \DateTimeImmutable($prior['asOf'])>=$priorCutoff) {
                        $item['comparable']=true;++$comparable;SalesCalendar::add($previous,$prior['totals']);SourceSalesAmounts::add($previous,$prior['totals']);TurnoverAmounts::add($previous,$prior['totals']);
                        $previous['salesOrders']+=$prior['totals']['salesOrders'];
                        foreach(['orderedUnits','soldUnits'] as $field)$previous[$field]=SalesUnitTotals::add($previous[$field],$prior['totals'][$field]);
                    }
                    for($day=$start;$day->format('Y-m-d')<=$to;$day=$day->modify('+1 day')) {
                        $key=$day->format('Y-m-d');$known=$coverageStart<=$day&&$asOf>=$day
                            &&($gapStart===null||$day<$gapStart||$day>=$gapEnd);
                        $unverified=isset($unverifiedDays[$key]);
                        // Verified orders still contribute on a day the source covers only in part.
                        $verified=$report['days'][$key]??null;
                        $value=$verified??($known&&!$unverified?[...SalesCalendar::ZERO,...SourceSalesAmounts::ZERO,...TurnoverAmounts::ZERO]:null);
                        $limited=$unverified||(!$known&&$verified!==null);
                        $item['days'][]=['date'=>$key,'turnoverCents'=>$value['turnoverCents']??null,'cancelledTurnoverCents'=>$value['cancelledTurnoverCents']??null,'salesTurnoverCents'=>$value['salesTurnoverCents']??null,'panelSalesCents'=>$value['panelSalesCents']??null,'grossCents'=>$value['grossCents']??null,'cancelCents'=>$value['cancelCents']??null,'returnCents'=>($report['refundsVerified']??true)?($value['returnCents']??null):null,'netCents'=>$value===null?null:$value['grossCents']-$value['cancelCents']-$value['returnCents'],'orders'=>$value['orders']??null,'partial'=>$value!==null&&($limited||$asOf<$day->setTime(23,59,59))];
                        $item['days'][array_key_last($item['days'])]['salesOrders']=$value['salesOrders']??$value['orders']??null;
                        if($limited)$item['days'][array_key_last($item['days'])]['coverageLimited']=true;
                    }
                    foreach($report['products'] as $p)$products[]=['channel'=>$market['name'],'panelBasis'=>$report['panelBasis']??'invoice','refundsVerified'=>$report['refundsVerified']??true,...$p];
                    if(!$full)$notes[]=$market['name'].': seçilen dönemin tamamı henüz aktarılmadı.';
                    if($report['missingProductOrders'])$notes[]=$market['name'].': bazı siparişlerin ürün ayrıntıları eksik.';
                    if($report['unknownQuantities'])$notes[]=$market['name'].': bazı ürünlerin net adedi teyit edilemiyor.';
                }
                $series[]=$item;
            }
            if(count($products)>5000)Http::fail(422,'Rapor çok fazla ürün içeriyor. Pazaryeri veya tarih aralığını daraltın.');
            $total=function(array $r):array{$r['netCents']=$r['grossCents']-$r['cancelCents']-$r['returnCents'];$r['salesTurnoverAverageCents']=$r['orders']&&$r['salesTurnoverCents']!==null?(int)round($r['salesTurnoverCents']/$r['orders']):null;$r['turnoverAverageCents']=$r['orders']&&$r['turnoverCents']!==null?(int)round($r['turnoverCents']/$r['orders']):null;$r['averageCents']=$r['orders']? (int)round($r['netCents']/$r['orders']):null;return $r;};
            $comparison=$available>0&&$available===$comparable;
            $companyRow=$this->db->one('SELECT id,name FROM companies WHERE id=?',[$company]);
            return ['company'=>[...$companyRow,...(new CustomerManagement($this->db))->profile($company,false)],'from'=>$from,'to'=>$to,'previousFrom'=>$previousFrom,'previousTo'=>$previousTo,
                'refundsVerified'=>$refundsVerified,'generatedAt'=>$clock->format(DATE_ATOM),'channel'=>$channel,'availableChannels'=>$available,'selectedChannels'=>count($markets),'totals'=>$available?$total($current):null,
                'previousTotals'=>$comparison?$total($previous):null,'comparisonAvailable'=>$comparison,'series'=>$series,'products'=>$products,'notes'=>$notes,
                'payments'=>(new PaymentCalendar($this->db))->report($user,$company,$from,$to,$channel,historyPreset:$historyPreset)];
        });
    }
}
