<?php
declare(strict_types=1);
namespace Omnigo;

final class PaymentCalendar
{
    public function __construct(private Database $db) {}
    public function report(array $user,int $company,string $from,string $to,string $channel='all',bool $includeUpcoming=false,bool $captureForecast=false,bool $historyPreset=false,?string $periodStart=null): array
    {
        ReportPeriod::range($from,$to,$historyPreset);$repo=new Repository($this->db);
        MarketplaceVisibility::requireVisible($channel);
        $channels=CustomerMarketplaces::reportingChannels($this->db,$company,$repo->records($company,'channels',$user));
        $payments=$repo->records($company,'payments',$user);
        $channels=CustomerMarketplaces::payoutChannels($channels,$payments);
        $allowed=array_column(array_filter($channels,fn($c)=>MarketplaceVisibility::visible($c['name'])&&($user['role']==='admin'||$c['status']==='active'||!empty($c['historical']))),'name');
        $admin = $user['role'] === 'admin';
        $forecast = $includeUpcoming && ($admin || (!empty($user['id'])
            && (new CustomerPermissions($this->db,$company))->allows($user,'payments')))
            ? (new UpcomingPaymentForecast($this->db))->read($company,$payments,$channels,$allowed,$channel,$admin,$captureForecast) : null;
        $payments=array_values(array_filter($payments,static fn(array $p):bool=>empty($p['integration'])
            &&empty($p['paymentStateUnknown'])&&empty($p['excludedFromTotals'])&&in_array($p['channel'],$allowed,true)));
        $periodView=$periodStart!==null&&$forecast!==null;
        if($periodView){
            $dates=$settled=$pendingIds=[];
            foreach($payments as $payment){
                $day=$payment['status']==='paid'?$payment['paidDate']:$payment['expectedDate'];
                if($day!=='')$dates[]=$day;
                if(in_array($payment['status'],['paid','sent'],true)){
                    $settled[$payment['channel']][$payment['expectedDate']]=true;
                }elseif($payment['status']==='pending'){
                    $pendingIds[$payment['id']]=true;
                }
            }
            [$period,$forecast['periods']]=PaymentPeriods::schedule(array_column($forecast['periodItems'],'date'),$dates,
                $from,$to,$forecast['today'],$periodStart);
            $from=$period['start'];$to=$period['end'];
            foreach(['periodItems','items','pendingChanges'] as $field){
                if(isset($forecast[$field]))$forecast[$field]=array_values(array_filter($forecast[$field],
                    static fn(array $item):bool=>PaymentApproval::isApproved($item)||(($item['source']??null)==='manual'
                        ? isset($pendingIds[$item['id']]) : !isset($settled[$item['channel']][$item['date']]))));
            }
            $published=[];
            foreach($forecast['periodItems'] as $item)if(($item['source']??null)==='manual')$published[(string)$item['id']]=true;
            $historic = array_filter($payments,static fn(array $p):bool=>$p['status']==='pending'
                &&$p['expectedDate']<$forecast['today']&&!isset($published[(string)$p['id']]));
            $historicalItems=UpcomingPayments::snapshot($historic,$allowed,'0001-01-01')['items'];
            array_push($forecast['periodItems'],...$historicalItems);
            if($admin&&$historicalItems){
                $includedIds=array_flip(array_column($historicalItems,'id'));
                foreach($historic as $payment){
                    if(isset($includedIds[$payment['id']]))$forecast['manualRecords'][]=$payment;
                }
            }
        }
        $items=[];$undatedItems=[];$legacyItems=[];$earningsItems=[];$undatedCents=0;$summary=['estimatedCents'=>0,'confirmedCents'=>0,'receivedCents'=>0,'sentCents'=>0];
        $missingPaymentChannels=[];
        foreach($payments as $p) {
            if($channel!=='all'&&$p['channel']!==$channel)continue;
            $expected=$p['expectedDate']>=$from&&$p['expectedDate']<=$to;
            $received=$p['status']==='paid'&&$p['paidDate']>=$from&&$p['paidDate']<=$to;
            $undated=$p['expectedDate']===''&&$p['status']!=='paid';
            if(!$expected&&!$received&&!$undated)continue;
            if($periodView&&$p['status']==='paid'&&!$received)continue;
            $verified=($p['verification']??'estimated')==='confirmed'&&!empty($p['verificationSource']);
            $p['verification']=$verified?'confirmed':'estimated';$p['verificationSource']=$verified?$p['verificationSource']:'';
            $p['calendarDate']=$p['status']==='paid'?$p['paidDate']:$p['expectedDate'];
            $p['inCalendar']=$p['calendarDate']>=$from&&$p['calendarDate']<=$to;
            if($undated){$p['inCalendar']=false;$undatedItems[]=$p;$undatedCents+=(int)$p['expectedCents'];continue;}
            // A saved bank receipt is reported, not final, until an Omnigo operator approves it.
            if($p['status']==='paid') {if($received)$summary[PaymentApproval::isApproved($p)?'receivedCents':'sentCents']+=(int)$p['actualCents'];}
            elseif($p['status']==='sent'&&$expected)$summary['sentCents']+=(int)$p['expectedCents'];
            elseif($expected)$summary[$verified?'confirmedCents':'estimatedCents']+=(int)$p['expectedCents'];
            $items[]=$p;
            if(count($items)>5000)Http::fail(422,'Bu dönem çok fazla hakediş içeriyor. Daha kısa bir tarih aralığı seçin.');
        }
        usort($items,fn($a,$b)=>strcmp($a['calendarDate'],$b['calendarDate'])?:($a['id']<=>$b['id']));
        // Keep empty compatibility fields for older clients; imported records stay in storage.
        return [...compact('from','to','channel','summary','items','undatedItems','undatedCents','legacyItems','earningsItems','missingPaymentChannels'),
            ...($forecast===null?[]:['upcoming'=>$forecast]), ...($periodView?['periodView'=>true]:[]),
            'marketplaces'=>array_values(array_unique($allowed)),'archivedItems'=>[],'archivedCount'=>0];
    }
}
