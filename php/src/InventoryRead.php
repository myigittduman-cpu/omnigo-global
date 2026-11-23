<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryRead
{
    public function __construct(private Database $db){}
    public function productPage(int $company,string $search='',int $page=0,string $filter='stocked',string $provider='',int $pageSize=10,string $category='',string $sort='stable',bool $fileCenter=false,string $brand=''): array
    {
        $pageSize=$pageSize===50?50:10;
        $page=max(0,min(100000,$page));$params=[$company];
        $filter=InventoryProductFilter::name($filter);
        $skuIdentity=InventoryLiveSkuIdentity::load($this->db,$company);
        $barcodeIdentity=$skuIdentity?null:InventoryBarcodeIdentity::load($this->db,$company);
        if($filter==='legacy'&&!$barcodeIdentity&&!$skuIdentity)$filter='stocked';
        $where='p.company_id=?';
        if(!$skuIdentity||$filter!=='legacy')
            $where.=' AND '.InventoryStockCard::primaryProductSql();
        $settings=(new InventorySettings($this->db))->preferences($company);
        [$filterSql,$filterParams]=InventoryProductFilter::where($company,$filter,$settings['mode']);
        $where.=' AND '.$filterSql;array_push($params,...$filterParams);
        if($fileCenter&&FileCenterSchema::available($this->db,FileCenterSchema::HIDDEN_PRODUCTS)){
            $where.=' AND NOT EXISTS (SELECT 1 FROM file_center_hidden_products h WHERE h.company_id=p.company_id AND h.product_key=p.product_key)';
        }
        if(($barcodeIdentity||$skuIdentity)&&$filter!=='removed'){
            $match=$skuIdentity?InventoryLiveSkuIdentity::confirmedSql():InventoryBarcodeIdentity::confirmedSql();
            $confirmed='EXISTS (SELECT 1 FROM inventory_listings l WHERE l.company_id=p.company_id AND l.product_key=p.product_key AND '.$match.')';
            $manual='EXISTS (SELECT 1 FROM inventory_manual_products m WHERE m.company_id=p.company_id AND m.product_key=p.product_key)';
            $where.=$filter==='legacy'?' AND NOT ('.$confirmed.' OR '.$manual.')':' AND ('.$confirmed.' OR '.$manual.')';
        }
        [$providerSql,$providerParams]=InventoryMarketplaceFilter::where($provider);
        $where.=' AND '.$providerSql;array_push($params,...$providerParams);
        $category=InventoryProductCategory::name($category);
        $categories=$fileCenter?[]:InventoryProductCategory::options($this->db,$where,$params,$category);
        $from=' FROM inventory_products p';
        $positions=$sort==='stable'&&InventoryProductPosition::available($this->db);
        if($positions)$from.=' LEFT JOIN inventory_product_positions o ON o.company_id=p.company_id AND o.product_key=p.product_key';
        $brands=[];
        if($fileCenter){
            $productBrands=new InventoryProductBrands($this->db);
            $brands=$productBrands->options($company,$where,$params);
            if($brand!==''){
                [$brandSql,$brandParams]=$productBrands->filter($brand);
                $from.=$productBrands->joins();
                $where.=' AND '.$brandSql;
                $params=[$company,$company,...$params,...$brandParams];
            }
        }
        if($category!==''){$where.=' AND ('.InventoryProductCategory::expression().')=?';$params[]=$category;}
        if(trim($search)!==''){
            $sourceTitle="CASE WHEN JSON_VALID(l.evidence) THEN JSON_EXTRACT(l.evidence,'$.sourceTitle') END";
            if($this->db->mysql())$sourceTitle="JSON_UNQUOTE($sourceTitle)";
            $where.=" AND (p.title LIKE ? ESCAPE '!' OR p.sku LIKE ? ESCAPE '!' OR p.barcode LIKE ? ESCAPE '!'
                OR EXISTS (SELECT 1 FROM inventory_listings l".InventoryStockCard::listingLinkSql()."
                    WHERE l.company_id=p.company_id AND ".InventoryStockCard::listingKeySql()."=p.product_key
                    AND l.remote_id NOT LIKE 'order-sku:%' AND (l.sku LIKE ? ESCAPE '!' OR $sourceTitle LIKE ? ESCAPE '!')))";
            $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($search)).'%';
            array_push($params,$term,$term,$term,$term,$term);
        }
        $total=(int)$this->db->one('SELECT COUNT(*) n'.$from.' WHERE '.$where,$params)['n'];
        $page=min($page,max(0,(int)ceil($total/$pageSize)-1));
        $rows=$this->db->all('SELECT p.*'.$from.' WHERE '.$where.' ORDER BY '.InventorySort::order($sort,$positions).' LIMIT '.$pageSize.' OFFSET '.($page*$pageSize),$params);
        return compact('rows','filter','category','categories','total','page','pageSize','settings','barcodeIdentity','skuIdentity','brands');
    }
    public function listing(int $company,string $search='',int $page=0,string $filter='stocked',string $provider='',int $pageSize=10,string $category='',string $sort='stable',?array $visibility=null): array
    {
        ['rows'=>$rows,'filter'=>$filter,'category'=>$category,'categories'=>$categories,'total'=>$total,'page'=>$page,
            'pageSize'=>$pageSize,'settings'=>$settings,'barcodeIdentity'=>$barcodeIdentity,'skuIdentity'=>$skuIdentity]
            =$this->productPage($company,$search,$page,$filter,$provider,$pageSize,$category,$sort);
        $keys=array_column($rows,'product_key');$listings=[];$stats=[];$storeSales=[];$pending=[];
        if($keys){
            foreach($this->db->all("SELECT product_key FROM inventory_commands WHERE company_id=? AND state='pending'",[$company]) as $command)$pending[$command['product_key']]=true;
            $in=implode(',',array_fill(0,count($keys),'?'));
            foreach($this->db->all('SELECT * FROM inventory_listings WHERE company_id=? AND product_key IN ('.$in.')',[$company,...$keys]) as $row)$listings[$row['product_key']][]=$row;
            $statistics=new InventoryStatistics($this->db);
            $stats=$statistics->forKeys($company,$keys,$visibility);
            $storeSales=$statistics->storeSalesForKeys($company,$keys,$visibility['returns']??true);
        }
        $marketplaceStocks=(new InventorySourceStock($this->db))->forKeys($company,$keys);
        $availability=(new InventoryMarketplaceAvailability($this->db))->forProducts($company,$rows,$marketplaceStocks,$stats);
        $sharedStocks=$settings['mode']==='horoz'?(new InventorySharedStock($this->db))->forKeys($company,$keys):[];
        $prices=(new InventoryPurchasePrices($this->db))->forKeys($company,$keys);
        $items=[];$states=(new InventoryProductState($this->db))->forKeys($company,$keys);$settings=(new InventorySettings($this->db))->preferences($company);
        foreach($rows as $row){
            $sources=[];$verified=true;
            foreach($listings[$row['product_key']]??[] as $source){$evidence=json_decode($source['evidence'],true,32,JSON_THROW_ON_ERROR);$sources[]=['sourceKey'=>$source['provider'].':'.$source['listing_key'],...$evidence];$verified=$verified&&!empty($evidence['identityVerified']);}
            // No retrospective continuity is implied by collected snapshots.
            $eligibility=InventoryCatalogEligibility::evaluate($sources,false,new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')));
            $snapshots=$marketplaceStocks[$row['product_key']]??[];$quantity=$row['quantity']===null?null:(int)$row['quantity'];
            $items[]=['key'=>$row['product_key'],'title'=>$row['title'],'sku'=>$row['sku'],'barcode'=>$row['barcode'],'imageUrl'=>InventoryImages::url($company,$row['product_key'],$row['image_url']),'quantity'=>$quantity,'version'=>(int)$row['version'],'removed'=>$filter==='removed','pending'=>isset($pending[$row['product_key']]),'updated'=>$row['updated'],'eligibility'=>$eligibility,'identityVerified'=>$verified,'storeSales'=>$storeSales[$row['product_key']]??0,'markets'=>$stats[$row['product_key']]??[],'marketplaceStocks'=>$snapshots,'marketplaceAvailability'=>$availability[$row['product_key']]??[],'listedOn'=>InventoryStockCard::providers($snapshots),...($states[$row['product_key']]??[]),...($prices[$row['product_key']]??['purchasePriceCents'=>null,'purchasePriceVersion'=>0]),...InventoryTotals::calculate($settings['mode'],$quantity,$snapshots,$sharedStocks[$row['product_key']]??[])];
        }
        $initialImport=(new InventoryInitialImport($this->db))->status($company);
        $job=$this->db->one('SELECT enabled,last_success,error FROM inventory_jobs WHERE company_id=?',[$company]);
        $catalogs=array_map(static fn($c)=>['provider'=>$c['provider'],'lastSuccess'=>$c['last_success'],'error'=>$c['error']],$this->db->all('SELECT provider,last_success,error FROM inventory_catalog_jobs WHERE company_id=? ORDER BY provider',[$company]));
        return ['filter'=>$filter,'category'=>$category,'categories'=>$categories,'settings'=>$settings,'initialImport'=>$initialImport,'metadata'=>InventoryMetadataQueue::status($this->db,$company),'progress'=>(new InventoryProgress($this->db))->status($company),'providers'=>array_map(fn($p)=>InventoryCatalogPage::LABELS[$p],InventoryCatalogPage::SUPPORTED),'pendingProducts'=>(new InventoryProductState($this->db))->pending($company),'catalogs'=>$catalogs,'items'=>$items,'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'imagesEnabled'=>true,'sync'=>['enabled'=>(bool)($job['enabled']??false),'lastSuccess'=>$job['last_success']??null,'error'=>$job['error']??null],'issues'=>(int)$this->db->one('SELECT COUNT(*) n FROM inventory_issues WHERE company_id=?',[$company])['n'],
            ...($barcodeIdentity?['barcodeOnly'=>true,'pendingBarcodeListings'=>$barcodeIdentity->pendingListings()]:[]),
            ...($skuIdentity?['skuOnly'=>true,'pendingSkuListings'=>$skuIdentity->pendingListings(),'skuConflicts'=>$skuIdentity->conflictCount()]:[])];
    }
}
