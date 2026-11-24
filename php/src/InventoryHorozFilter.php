<?php
declare(strict_types=1);
namespace Omnigo;

/** Apply the same deterministic live reference and manual overlay before paging. */
final class InventoryHorozFilter
{
    public static function stocked(int $company):array
    {
        return self::quantityFilter($company,'horoz',false);
    }
    public static function depleted(int $company,string $mode):array
    {
        if($mode==='store')return ['p.quantity<=0',[]];
        return self::quantityFilter($company,$mode,true);
    }
    private static function quantityFilter(int $company,string $mode,bool $depleted):array
    {
        $comparison=$depleted?'<=0':'>0';
        $amount=match($mode){'horoz'=>'ranked.source_quantity+COALESCE(h.adjustment,0)','both'=>'ranked.source_quantity+p.quantity',default=>'ranked.source_quantity'};
        $saleState=$depleted?'':"JSON_EXTRACT(ranked.evidence,'$.saleActive')=1 AND ";
        $jobCase='CASE s.provider';
        foreach(InventoryCatalogPage::LABELS as $key=>$label)$jobCase.=" WHEN '".str_replace("'","''",$label)."' THEN '".$key."'";
        $jobCase.=' END';$priority='CASE s.provider';
        foreach(['Hepsiburada','n11','Koçtaş','PTT AVM','Pazarama','idefix','Trendyol'] as $rank=>$label)$priority.=" WHEN '".$label."' THEN ".$rank;
        $priority.=' ELSE 7 END';$cutoff=gmdate('c',time()-1800);
        $query="p.product_key IN (SELECT ranked.product_key FROM (
            SELECT s.product_key,s.source_quantity,s.evidence,
              ROW_NUMBER() OVER (PARTITION BY s.product_key ORDER BY
                CASE WHEN JSON_EXTRACT(s.evidence,'$.saleActive')=1 THEN CASE WHEN s.source_quantity>0 THEN 0 ELSE 1 END WHEN JSON_EXTRACT(s.evidence,'$.saleActive')=0 THEN 3 ELSE 2 END,
                $priority,s.provider,s.sku,s.observed_at DESC,s.source_quantity) stock_rank
            FROM (
              SELECT product_key,provider,sku,source_quantity,observed_at,evidence FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%'
            ) s JOIN inventory_catalog_jobs j ON j.company_id=? AND j.provider=$jobCase
            WHERE s.source_quantity IS NOT NULL AND s.observed_at>=? AND j.last_success>=? AND COALESCE(j.error,'')=''
          ) ranked LEFT JOIN inventory_shared_stock h ON h.company_id=? AND h.product_key=ranked.product_key
          WHERE ranked.stock_rank=1 AND $saleState $amount $comparison)";
        if($mode==='marketplace')return [$query,[$company,$company,$cutoff,$cutoff,$company]];
        $manualAmount=$mode==='horoz'?"EXISTS(SELECT 1 FROM inventory_shared_stock h WHERE h.company_id=p.company_id AND h.product_key=p.product_key AND h.manual_quantity IS NOT NULL AND h.manual_quantity+h.adjustment $comparison)":"p.quantity $comparison";
        $manual="$manualAmount
          AND NOT EXISTS(SELECT 1 FROM inventory_listings l WHERE l.company_id=p.company_id AND l.product_key=p.product_key AND l.remote_id NOT LIKE 'order-sku:%')";
        return ['('.$query.' OR ('.$manual.'))',[$company,$company,$cutoff,$cutoff,$company]];
    }
}
