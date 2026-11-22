<?php
declare(strict_types=1);
namespace Omnigo;

/** One row per listing, using the sale status and stock from that specific marketplace. */
final class ActiveMarketplaceListings
{
    private const PAGE_SIZE=15;
    private const SALE_PROVIDERS=['hepsiburada','n11','koctas','pttavm','trendyol'];
    public function __construct(private Database $db) {}

    public function page(int $company,string $query,string $provider,int $page): array
    {
        $read=function()use($company,$query,$provider,$page){
            $scope=$this->scope($company);
            $empty=['items'=>[],'providers'=>[],'total'=>0,'page'=>0,'pageSize'=>self::PAGE_SIZE];
            if(!$scope)return $empty;
            [$from,$params]=$scope;
            $providers=array_column($this->db->all('SELECT DISTINCT l.provider '.$from.' ORDER BY l.provider',$params),'provider');
            if(!$providers)return $empty;
            if($provider!==''){$from.=' AND l.provider=?';$params[]=$provider;}
            if($query!==''){
                $from.=" AND (l.sku LIKE ? ESCAPE '!' OR ".$this->title()." LIKE ? ESCAPE '!')";
                $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
                array_push($params,$term,$term);
            }
            $total=(int)$this->db->one('SELECT COUNT(*) n '.$from,$params)['n'];
            $page=max(0,min($page,(int)ceil($total/self::PAGE_SIZE)-1));
            $rows=$this->db->all($this->columns().$from.' ORDER BY l.provider,l.sku,l.listing_key LIMIT '.self::PAGE_SIZE.' OFFSET '.($page*self::PAGE_SIZE),$params);
            $targets=(new ActiveMarketplaceListingTargets($this->db))->forRows($company,$rows,$scope[2]);
            $items=[];
            foreach($rows as $i=>$row){
                $target=$targets[$i]??null;
                if($target!==null)unset($target['remoteId'],$target['catalogSku']);
                $evidence=json_decode($row['evidence']??'{}',true);
                $title='';
                foreach([$row['title'],$evidence['metadataTitle']??'',$row['product_title']] as $candidate){
                    $candidate=trim((string)$candidate);
                    if($candidate!==''&&strcasecmp($candidate,$row['sku'])!==0&&!str_starts_with($candidate,'Stok kodu:')){
                        $title=$candidate;break;
                    }
                }
                $items[]=['key'=>$row['listing_key'],'provider'=>$row['provider'],'sku'=>$row['sku'],'title'=>$title,'priceCents'=>$evidence['offerPriceCents']??null,
                    'imageUrl'=>InventoryImages::url($company,$row['product_key'],$row['image_url']??''),
                    'quantity'=>self::quantity($row),'observedAt'=>$row['observed_at'],'writeTarget'=>$target];
            }
            return compact('items','providers','total','page')+['pageSize'=>self::PAGE_SIZE];
        };
        return $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
    }

    public function writableTarget(int $company,string $productKey,string $listingKey): ?array
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$productKey)||!preg_match('/^[0-9a-f]{64}$/D',$listingKey))return null;
        foreach($this->writableProductTargets($company,$productKey) as $target){
            if($target['key']===$listingKey)return $target;
        }
        return null;
    }

    public function writableProductTargets(int $company,string $productKey): array
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$productKey))return [];
        $scope=$this->scope($company);
        if(!$scope)return [];
        [$from,$params]=$scope;
        $rows=$this->db->all($this->columns().$from.' AND l.product_key=? ORDER BY l.provider,l.listing_key',[...$params,$productKey]);
        return array_values((new ActiveMarketplaceListingTargets($this->db))->forRows($company,$rows,$scope[2]));
    }

    public static function quantity(array $row): ?int
    {
        $quantity=filter_var($row['source_quantity'],FILTER_VALIDATE_INT);
        return $quantity!==false&&$quantity>=0&&$quantity<=100000000?$quantity:null;
    }

    private function title(): string
    {
        $source="JSON_EXTRACT(l.evidence,'$.sourceTitle')";
        if($this->db->mysql())$source="JSON_UNQUOTE($source)";
        // Older catalog rows can lack a source title. A manually entered name is local to inventory.
        return "COALESCE(NULLIF(TRIM(CASE WHEN JSON_VALID(l.evidence) THEN $source END),''),CASE WHEN m.product_key IS NULL THEN NULLIF(TRIM(p.title),'') END,l.sku)";
    }

    private function columns(): string
    {
        return "SELECT l.listing_key,l.product_key,p.product_key local_product,l.provider,l.sku,l.remote_id,l.source_quantity,l.observed_at,l.evidence,p.image_url,p.title product_title,".$this->title().' title ';
    }

    private function scope(int $company): ?array
    {
        $now=time();
        $summary=new IntegrationImportCatalogSummary($this->db);
        $sources=$summary->sources($company,self::SALE_PROVIDERS,$now);
        $windows=[];$params=[$company];$cutoffs=[];
        foreach($sources as $key=>$source){
            if($source['state']!=='verified')continue;
            $windows[]='(l.provider=? AND l.observed_at>=?)';
            $label=InventoryCatalogPage::LABELS[$key];
            $cutoffs[$label]=gmdate('Y-m-d\TH:i:s',max($source['startedAt'],$now-1800));
            array_push($params,$label,$cutoffs[$label]);
        }
        if(!$windows)return null;
        $from=' FROM inventory_listings l LEFT JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key'
            .' LEFT JOIN inventory_manual_products m ON m.company_id=l.company_id AND m.product_key=l.product_key'
            ." WHERE l.company_id=? AND l.remote_id<>'' AND l.remote_id NOT LIKE 'order-sku:%' AND (".implode(' OR ',$windows).')'
            ." AND l.observed_at<=? AND (".$summary->saleExpression().")=1"
            .' AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=l.company_id AND r.product_key=l.product_key)';
        $params[]=gmdate('Y-m-d\TH:i:s\Z',$now+300);
        // Compare explicit test markers; a real product mentioning a testing feature remains eligible.
        foreach(['l.sku',$this->title()] as $field){
            $marker="REPLACE(LOWER(TRIM($field)),'Ü','ü')";
            $from.=" AND $marker NOT IN ('deneme','test','demo','deneme ürünü','test ürünü','demo ürün','demo ürünü','test product')"
                ." AND $marker NOT LIKE 'deneme-%' AND $marker NOT LIKE 'test-%' AND $marker NOT LIKE 'deneme ürün%' AND $marker NOT LIKE 'test ürün%'";
        }
        return [$from,$params,$cutoffs];
    }
}
