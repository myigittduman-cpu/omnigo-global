<?php
declare(strict_types=1);
namespace Omnigo;

final class ProfitabilityReport
{
    private const PAGE_SIZE=10;

    public function __construct(private Database $db) {}

    public function read(array $user,int $company,array $filters): array
    {
        Security::admin($user);
        $from=$filters['from'];$to=$filters['to'];$channel=$filters['channel'];
        [$start,$end]=ReportPeriod::range($from,$to);
        MarketplaceVisibility::requireVisible($channel);
        $page=filter_var($filters['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if($page===false)Http::fail(400,'Sayfa numarası geçersiz.');
        $active=ProfitabilitySchema::startedAt($this->db);
        $startedAt=$active===null?null:gmdate('c',$active);
        $marketplaces=$this->marketplaces($company,$user);
        $meta=[...compact('from','to','channel','marketplaces','startedAt'),'pageSize'=>self::PAGE_SIZE,
            'commissionBasisPoints'=>null,'shippingCents'=>null,'calculationBasis'=>'marketplace_deductions'];
        if($active===null||($channel!=='all'&&!in_array($channel,$marketplaces,true)))return [...$meta,'page'=>1,'total'=>0,'rows'=>[]];
        $scope='company_id=? AND ordered_at BETWEEN ? AND ? AND '.MarketplaceVisibility::sql();
        $args=[$company,max($active,$start->getTimestamp()),$end->getTimestamp()];
        if($channel!=='all'){
            $scope.=' AND provider=?';
            $args[]=array_search($channel,OrderList::PROVIDERS,true);
        }
        return $this->db->transaction(function()use($company,$scope,$args,$page,$meta){
            $total=(int)$this->db->one('SELECT COUNT(*) AS n FROM profitability_orders WHERE '.$scope,$args)['n'];
            $page=min($page,max(1,intdiv(max(0,$total-1),self::PAGE_SIZE)+1));
            $orders=$this->db->all('SELECT * FROM profitability_orders WHERE '.$scope.
                ' ORDER BY ordered_at DESC,provider,external_id LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),$args);
            $sources=(new ProfitabilityOrderSales($this->db))->read($company,$orders);
            $rates=(new ProfitabilityCommissionRates($this->db))->all($company);
            $observed=ProfitabilityCommissionSync::observations($this->db,$company,$orders);
            $history=new ProfitabilityCostHistory($this->db);
            $useRules=$history->available();
            $rows=[];
            foreach($orders as $order){
                $provider=$order['provider'];$id=$order['external_id'];
                $lines=$history->lines($company,$order,$useRules);
                $rows[]=ProfitabilityCalculator::row($order,$sources[$provider][$id]??null,$lines,$rates,$observed[$provider][$id]??null);
            }
            return [...$meta,...compact('page','total','rows')];
        });
    }

    private function marketplaces(int $company,array $user): array
    {
        $repository = new Repository($this->db);
        $registered = $repository->records($company,'channels',$user);
        $marketplaces=CustomerMarketplaces::reportingChannels($this->db,$company,$registered);
        return array_values(array_intersect(Repository::CHANNELS,OrderList::PROVIDERS,array_column($marketplaces,'name')));
    }
}
