<?php
declare(strict_types=1);
namespace Omnigo;

final class PaymentCalendar
{
    public function __construct(private Database $db) {}
    public function report(array $user,int $company,string $from,string $to,string $channel='all',bool $includeUpcoming=false,bool $captureForecast=false,bool $historyPreset=false): array
    {
        ReportPeriod::range($from,$to,$historyPreset);$repo=new Repository($this->db);
        MarketplaceVisibility::requireVisible($channel);
        $channels=CustomerMarketplaces::reportingChannels($this->db,$company,$repo->records($company,'channels',$user));
        $allowed=array_column(array_filter($channels,fn($c)=>MarketplaceVisibility::visible($c['name'])&&($user['role']==='admin'||$c['status']==='active'||!empty($c['historical']))),'name');
        $items=[];$undatedItems=[];$legacyItems=[];$earningsItems=[];$undatedCents=0;$summary=['estimatedCents'=>0,'confirmedCents'=>0,'receivedCents'=>0,'sentCents'=>0];
        $payments=$repo->records($company,'payments',$user);
        $missingPaymentChannels=[];
        foreach($payments as $p) {
            // Only administrator-entered payouts belong in this report.
            if(!empty($p['integration'])||!empty($p['paymentStateUnknown'])||!empty($p['excludedFromTotals']))continue;
            if(!in_array($p['channel'],$allowed,true)||($channel!=='all'&&$p['channel']!==$channel))continue;
            $expected=$p['expectedDate']>=$from&&$p['expectedDate']<=$to;
            $received=$p['status']==='paid'&&$p['paidDate']>=$from&&$p['paidDate']<=$to;
            $undated=$p['expectedDate']===''&&$p['status']!=='paid';
            if(!$expected&&!$received&&!$undated)continue;
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
        $upcoming = [];
        $admin = $user['role'] === 'admin';
        if ($includeUpcoming && ($admin || (!empty($user['id'])
            && (new CustomerPermissions($this->db,$company))->allows($user,'payments')))) {
            $upcoming = ['upcoming'=>(new UpcomingPaymentForecast($this->db))->read($company,$payments,$channels,$allowed,$channel,$admin,$captureForecast)];
        }
        return [...compact('from','to','channel','summary','items','undatedItems','undatedCents','legacyItems','earningsItems','missingPaymentChannels'), ...$upcoming,
            'marketplaces'=>array_values(array_unique($allowed)),'archivedItems'=>[],'archivedCount'=>0];
    }
}
