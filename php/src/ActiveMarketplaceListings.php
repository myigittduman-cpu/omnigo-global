<?php
declare(strict_types=1);
namespace Omnigo;

/** Active offers grouped by inventory identity and verified marketplace links. */
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
            $card=$this->cardKey();$filtered=$from;$filterParams=$params;
            if($provider!==''){$filtered.=' AND l.provider=?';$filterParams[]=$provider;}
            if($query!==''){
                $filtered.=" AND (l.sku LIKE ? ESCAPE '!' OR ".$this->title()." LIKE ? ESCAPE '!' OR cp.sku LIKE ? ESCAPE '!' OR cp.title LIKE ? ESCAPE '!')";
                $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
                array_push($filterParams,$term,$term,$term,$term);
            }
            $total=(int)$this->db->one('SELECT COUNT(DISTINCT '.$card.') n '.$filtered,$filterParams)['n'];
            $page=max(0,min($page,(int)ceil($total/self::PAGE_SIZE)-1));
            if(!$total)return [...$empty,'providers'=>$providers];
            $keys=array_column($this->db->all('SELECT '.$card.' card_key '.$filtered.' GROUP BY '.$card
                .' ORDER BY MIN(l.provider),MIN(l.sku),card_key LIMIT '.self::PAGE_SIZE.' OFFSET '.($page*self::PAGE_SIZE),$filterParams),'card_key');
            $slots=implode(',',array_fill(0,count($keys),'?'));
            $rows=$this->db->all($this->columns().$from.' AND '.$card.' IN ('.$slots.') ORDER BY l.provider,l.listing_key',[...$params,...$keys]);
            $targets=(new ActiveMarketplaceListingTargets($this->db))->forRows($company,$rows,$scope[2]);
            $groups=[];
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
                $groups[$row['card_key']][]=['key'=>$row['listing_key'],'provider'=>$row['provider'],'sku'=>$row['sku'],'title'=>$title,
                    'priceCents'=>is_int($evidence['offerPriceCents']??null)?$evidence['offerPriceCents']:null,
                    'imageUrl'=>InventoryImages::url($company,$row['card_key'],$row['image_url']??''),
                    'cardSku'=>$row['card_sku'],'cardTitle'=>$row['card_title'],'linked'=>$row['card_key']!==$row['product_key'],
                    'quantity'=>self::quantity($row),'observedAt'=>$row['observed_at'],'writeTarget'=>$target];
            }
            $items=[];
            foreach($keys as $key)$items[]=$this->card($key,$groups[$key]);
            return compact('items','providers','total','page')+['pageSize'=>self::PAGE_SIZE];
        };
        return $this->db->pdo->inTransaction()?$read():$this->db->transaction($read);
    }

    private function card(string $key,array $offers): array
    {
        $first=$offers[0];
        $providers=InventoryStockCard::providers($offers);
        $target=array_values(array_filter(array_column($offers,'writeTarget')))[0]??null;
        $mixedQuantity=(bool)array_filter($offers,static fn($offer)=>$offer['quantity']!==$first['quantity']);
        $mixedPrice=(bool)array_filter($offers,static fn($offer)=>$offer['priceCents']!==$first['priceCents']);
        $sku=$first['cardSku']?:$first['sku'];
        $title=count($offers)>1||$first['linked']?trim((string)$first['cardTitle']):'';
        if($title===''||strcasecmp($title,$sku)===0||str_starts_with($title,'Stok kodu:')){
            $title=array_values(array_filter(array_column($offers,'title')))[0]??'';
        }
        unset($first['cardTitle'],$first['cardSku'],$first['linked']);
        return [...$first,'key'=>$key,'cardKey'=>$key,'providers'=>$providers,'offerCount'=>count($offers),'writeTarget'=>$target,
            'sku'=>$sku,'title'=>$title,'imageUrl'=>array_values(array_filter(array_column($offers,'imageUrl')))[0]??null,
            'quantity'=>$mixedQuantity?null:$first['quantity'],'mixedQuantity'=>$mixedQuantity,
            'priceCents'=>$mixedPrice?null:$first['priceCents'],'mixedPrice'=>$mixedPrice,
            'observedAt'=>max(array_column($offers,'observedAt'))];
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
        return $this->targets($company,$productKey,'l.product_key');
    }

    public function writableCardTargets(int $company,string $cardKey): array
    {
        return $this->targets($company,$cardKey,$this->cardKey());
    }

    public function cardProviders(int $company,string $key): array
    {
        $scope=$this->scope($company);
        if(!$scope)return [];
        [$from,$params]=$scope;
        return array_column($this->db->all('SELECT DISTINCT l.provider '.$from.' AND '.$this->cardKey().'=?',[...$params,$key]),'provider');
    }

    private function targets(int $company,string $productKey,string $keyColumn): array
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$productKey))return [];
        $scope=$this->scope($company);
        if(!$scope)return [];
        [$from,$params]=$scope;
        $rows=$this->db->all($this->columns().$from.' AND '.$keyColumn.'=? ORDER BY l.provider,l.listing_key',[...$params,$productKey]);
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
        return "SELECT l.listing_key,l.product_key,p.product_key local_product,l.provider,l.sku,l.remote_id,l.source_quantity,l.observed_at,l.evidence,"
            ."COALESCE(NULLIF(cp.image_url,''),p.image_url) image_url,p.title product_title,cp.sku card_sku,"
            ."cp.title card_title,".$this->cardKey().' card_key,'.$this->title().' title ';
    }

    private function cardKey(): string{return InventoryStockCard::listingKeySql();}

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
            .InventoryStockCard::listingLinkSql()
            .' LEFT JOIN inventory_products cp ON cp.company_id=l.company_id AND cp.product_key='.$this->cardKey()
            ." WHERE l.company_id=? AND l.remote_id<>'' AND l.remote_id NOT LIKE 'order-sku:%' AND (".implode(' OR ',$windows).')'
            ." AND l.observed_at<=? AND (".$summary->saleExpression().")=1"
            .' AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=l.company_id AND r.product_key IN (l.product_key,'.$this->cardKey().'))';
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
