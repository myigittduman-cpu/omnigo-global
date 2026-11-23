<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceListingCoverage
{
    public function __construct(private Database $db,private ?IntegrationVault $vault=null){}

    public function unavailable(int $company,string $key,array $targets):array
    {
        $vault=$this->vault??new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key');
        $connected=[];
        foreach(InventoryCatalogPage::SUPPORTED as $provider){
            if(!empty($vault->get($company,$provider)['enabled']))$connected[]=$provider;
        }
        $now=time();
        $summary=new IntegrationImportCatalogSummary($this->db);
        $sources=$summary->sources($company,$connected,$now);
        $ready=array_column($targets,'provider');
        $result=[];
        foreach($connected as $provider){
            $label=InventoryCatalogPage::LABELS[$provider];
            if(in_array($label,$ready,true))continue;
            $state='unverified';
            if($sources[$provider]['state']==='verified'){
                $start=gmdate('Y-m-d\TH:i:s',max($now-1800,$sources[$provider]['startedAt']));
                $sale=$summary->saleExpression();
                $rows=$this->db->all("SELECT l.product_key,$sale active FROM inventory_listings l
                    WHERE l.company_id=? AND (l.product_key=? OR l.sku IN
                    (SELECT sku FROM inventory_listings WHERE company_id=? AND product_key=? AND sku<>''))
                    AND l.provider=? AND l.remote_id NOT LIKE 'order-sku:%' AND l.observed_at>=? AND l.observed_at<=?",
                    [$company,$key,$company,$key,$label,$start,gmdate('Y-m-d\TH:i:s\Z',$now+300)]);
                $state='missing';
                foreach($rows as $row){
                    if($row['active']===null||$row['product_key']!==$key){$state='unverified';break;}
                    if((int)$row['active']===1)$state='ineligible';
                }
            }
            $result[]=['provider'=>$label,'state'=>$state];
        }
        return $result;
    }
}
