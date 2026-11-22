<?php
declare(strict_types=1);
namespace Omnigo;

/** Stock visibility is derived from current snapshots, without any restock approval state. */
final class InventoryProductFilter
{
    public static function name(string $filter): string
    {
        return in_array($filter,['stocked','depleted','all','removed','legacy'],true)?$filter:'stocked';
    }

    public static function where(int $company, string $filter, string $mode): array
    {
        $filter=self::name($filter);
        $removed='EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=p.company_id AND r.product_key=p.product_key)';
        $where=$filter==='removed'?$removed:'NOT '.$removed;
        if ($filter==='depleted') {[$depleted,$params]=InventoryHorozFilter::depleted($company,$mode);return [$where.' AND '.$depleted,$params];}
        if ($filter!=='stocked') return [$where,[]];
        if ($mode==='store') return [$where.' AND p.quantity>0',[]];
        if ($mode==='horoz') {[$stocked,$params]=InventoryHorozFilter::stocked($company);return [$where.' AND '.$stocked,$params];}

        $case='CASE s.provider'; $params=[$company,$company];
        foreach (InventoryCatalogPage::LABELS as $provider=>$label) {
            $case.=' WHEN ? THEN ?'; array_push($params,$label,$provider);
        }
        $case.=' END';
        // Filter before pagination. The selected reference must have a fresh,
        // positive, sale-open source; disagreement elsewhere does not hide it.
        $stocked="p.product_key IN (SELECT s.product_key FROM (
            SELECT product_key,provider,source_quantity,observed_at,evidence FROM inventory_listings
              WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%'
            UNION ALL
            SELECT k.product_key,l.provider,l.source_quantity,l.observed_at,l.evidence FROM inventory_links k
              JOIN inventory_listings l ON l.company_id=k.company_id AND l.product_key=k.source_product AND l.provider=k.provider
              WHERE k.company_id=? AND k.state='active' AND l.remote_id NOT LIKE 'order-sku:%'
            ) s LEFT JOIN inventory_catalog_jobs j ON j.company_id=? AND j.provider=$case
            GROUP BY s.product_key HAVING SUM(CASE WHEN s.source_quantity>0
              AND JSON_EXTRACT(s.evidence,'$.saleActive')=1 AND s.observed_at>=?
              AND j.last_success>=? AND COALESCE(j.error,'')='' THEN 1 ELSE 0 END)>0)";
        array_splice($params,2,0,[$company]);
        $cutoff=gmdate('c',time()-1800); array_push($params,$cutoff,$cutoff);
        // A separate manual-only product can be stocked without a marketplace listing.
        // Manual stock must not make a sale-closed marketplace product appear active.
        if ($mode==='both') $stocked='('.$stocked." OR (p.quantity>0
            AND NOT EXISTS (SELECT 1 FROM inventory_listings l WHERE l.company_id=p.company_id AND l.product_key=p.product_key AND l.remote_id NOT LIKE 'order-sku:%')
            AND NOT EXISTS (SELECT 1 FROM inventory_links k WHERE k.company_id=p.company_id AND k.product_key=p.product_key AND k.state='active')))";
        return [$where.' AND '.$stocked,$params];
    }
}
