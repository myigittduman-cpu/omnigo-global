<?php
declare(strict_types=1);
namespace Omnigo;

/** Counts describe the persisted provider snapshot, not invented created/updated statistics. */
final class IntegrationImportCounts
{
    public function __construct(private Database $db){}
    public function snapshot(int $company,string $provider,array $sync,bool $orders,bool $catalog,int $notBefore=0):array
    {
        $counts=['products'=>null,'stockRecords'=>null,'orders'=>null,'returns'=>null];
        if($catalog){
            $label=IntegrationImportPresentation::PROVIDERS[$provider];
            $counts['products']=(int)$this->db->one('SELECT COUNT(DISTINCT product_key) n FROM inventory_listings WHERE company_id=? AND provider=?',[$company,$label])['n'];
            $counts['stockRecords']=(int)$this->db->one('SELECT COUNT(*) n FROM inventory_listings WHERE company_id=? AND provider=? AND source_quantity IS NOT NULL',[$company,$label])['n'];
        }
        if($orders){
            if(in_array($provider,['hepsiburada','pttavm','trendyol'],true)){
                if(RecentOrderSchema::available($this->db))$counts['orders']=(int)$this->db->one('SELECT COUNT(*) n FROM recent_orders WHERE company_id=? AND provider=?',[$company,$provider])['n'];
            }else $counts['orders']=(int)$this->db->one('SELECT COUNT(*) n FROM records WHERE company_id=? AND kind=?',[$company,'order_'.$provider])['n'];
            $requests=$sync['returnRequests']??null;
            if(is_array($requests)&&empty($sync['returnRequestError'])&&isset($requests['checkedAt'],$requests['through'])
                &&(strtotime($requests['checkedAt'])?:0)>=$notBefore&&is_array($requests['items']??null))$counts['returns']=count($requests['items']);
        }
        return $counts;
    }
}
