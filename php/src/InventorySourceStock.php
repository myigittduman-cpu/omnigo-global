<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only marketplace snapshots. Shared stock is never summed across listings. */
final class InventorySourceStock
{
    public function __construct(private Database $db){}
    public function forKeys(int $company,array $keys,bool $includeOfferPrice=false):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$params=[$company,...$keys];$result=[];
        $jobs=[];foreach($this->db->all('SELECT provider,last_success,error FROM inventory_catalog_jobs WHERE company_id=?',[$company]) as $job)$jobs[InventoryCatalogPage::LABELS[$job['provider']]??$job['provider']]=$job;
        $rows=$this->db->all("SELECT l.product_key,l.provider,l.listing_key,l.remote_id,l.sku,l.source_quantity,l.observed_at,l.evidence,p.title FROM inventory_listings l LEFT JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key WHERE l.company_id=? AND l.remote_id NOT LIKE 'order-sku:%' AND l.product_key IN (".$in.')',$params);
        $linked=$this->db->all("SELECT k.product_key,l.provider,l.listing_key,l.remote_id,l.sku,l.source_quantity,l.observed_at,l.evidence,p.title FROM inventory_links k JOIN inventory_listings l ON l.company_id=k.company_id AND l.product_key=k.source_product AND l.provider=k.provider LEFT JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key WHERE k.company_id=? AND k.state='active' AND l.remote_id NOT LIKE 'order-sku:%' AND k.product_key IN (".$in.')',$params);
        foreach([...$rows,...$linked] as $row){
            $id=$row['provider'].':'.$row['listing_key'];
            $job=$jobs[$row['provider']]??[];
            $fresh=strtotime($row['observed_at'])>=time()-1800&&strtotime($job['last_success']??'')>=time()-1800;
            $health=!empty($job['error'])?'error':(!$fresh?'stale':($row['source_quantity']===null?'unknown':'current'));
            $evidence=json_decode($row['evidence'],true)?:[];
            $sale=$evidence['saleActive']??null;
            $result[$row['product_key']][$id]=['provider'=>$row['provider'],'sku'=>$row['sku'],'quantity'=>$row['source_quantity']===null?null:(int)$row['source_quantity'],'observedAt'=>$row['observed_at'],'health'=>$health,'saleActive'=>$sale===1?true:($sale===0?false:null)];
            $panel=$evidence['panelObservation']??null;
            if(is_array($panel)&&isset($panel['label'],$panel['observedAt'])){
                $snapshot=['companyId'=>$company,'provider'=>$row['provider'],'listingId'=>$row['remote_id'],
                    'sku'=>$row['sku'],'barcode'=>$row['remote_id'],'title'=>$evidence['sourceTitle']??$row['title'],
                    'quantity'=>$result[$row['product_key']][$id]['quantity']];
                $result[$row['product_key']][$id]['lastPanelCheck']=['label'=>$panel['label'],'observedAt'=>gmdate('c',$panel['observedAt']),
                    'current'=>$health==='current'&&InventoryPanelObservation::current($snapshot,$panel,time())!==null];
            }
            $idefix = IdefixStockEvidence::read($row, $evidence);
            if ($idefix !== null) $result[$row['product_key']][$id]['idefixStockCheck'] = $idefix;
            if($includeOfferPrice){
                $price=$evidence['offerPriceCents']??null;
                $result[$row['product_key']][$id]['offerPriceCents']=is_int($price)&&$price>0?$price:null;
            }
        }
        return array_map('array_values',$result);
    }
}
