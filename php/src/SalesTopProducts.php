<?php
declare(strict_types=1);
namespace Omnigo;

/** Overview product ranking reads local sales only; it never calls marketplace APIs. */
final class SalesTopProducts
{
    public function __construct(private Database $db){}
    public function report(array $user,int $company,string $from,string $to,string $channel='all'):array
    {
        ReportPeriod::range($from,$to);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        if($to>$today)Http::fail(400,'Bitiş tarihi bugünden sonra olamaz.');
        return $this->db->transaction(function()use($user,$company,$from,$to,$channel){
            $channels=CustomerMarketplaces::reportingChannels($this->db,$company,(new Repository($this->db))->records($company,'channels',$user));
            $channels=array_values(array_filter($channels,fn($c)=>MarketplaceVisibility::visible($c['name'])&&($user['role']==='admin'||$c['status']==='active'||!empty($c['historical']))&&($channel==='all'||$c['name']===$channel)));
            if($channel!=='all'&&!$channels)Http::fail(404,'Pazaryeri bulunamadı.');
            $products=[];$notes=[];$available=0;$rankingAvailable=true;
            foreach($channels as $market){
                $report=(new SalesAnalytics($this->db))->report($user,$company,$market['name'],$from,$to,0,5000);
                if($report['hasMore'])Http::fail(422,'Daha kısa bir tarih aralığı seçin.');
                if(!$report['available']){$notes[]=$market['name'].': '.$report['message'];continue;}
                $available++;
                foreach($report['products'] as $product)$products[]=[...$product,'channel'=>$market['name'],'refundsVerified'=>$report['refundsVerified']];
                if($report['missingProductOrders']){$rankingAvailable=false;$notes[]=$market['name'].': bazı siparişlerin ürün ayrıntıları bekleniyor. Sıralama tamamlandığında gösterilecek.';}
                if($report['partialHistory'])$notes[]=$market['name'].': dönemin tamamı henüz aktarılmadı.';
                if(!$report['refundsVerified'])$notes[]=$market['name'].': iade verileri henüz dahil değil.';
            }
            return ['products'=>$rankingAvailable?$products:[],'rankingAvailable'=>$rankingAvailable,'notes'=>$notes,'availableChannels'=>$available,'selectedChannels'=>count($channels),'from'=>$from,'to'=>$to];
        });
    }
}
