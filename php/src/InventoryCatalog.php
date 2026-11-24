<?php
declare(strict_types=1);
namespace Omnigo;

/** Normalized, read-only catalog intake. Provider adapters must supply real source identifiers. */
final class InventoryCatalog
{
    public const PROVIDERS=['Hepsiburada','n11','idefix','Pazarama','PTT AVM','Koçtaş','Trendyol'];
    public function __construct(private Database $db){}
    public static function validGtin(string $value): bool
    {
        if(!preg_match('/^(?:[0-9]{8}|[0-9]{12,14})$/D',$value))return false;
        if(trim($value,'0')==='')return false;
        $sum=0;$factor=3;
        for($i=strlen($value)-2;$i>=0;$i--){$sum+=(int)$value[$i]*$factor;$factor=$factor===3?1:3;}
        return (10-$sum%10)%10===(int)substr($value,-1);
    }
    public function ingest(int $company,string $provider,array $listing): string
    {
        return $this->writeListing($company,$provider,$listing,false);
    }
    /** Return null for previously seen source rows or hidden canonical products. */
    public function ingestNew(int $company,string $provider,array $listing): ?string
    {
        return $this->protectedIntake($company,$provider,$listing,true);
    }
    public function ingestReplay(int $company,string $provider,array $listing): ?string
    {
        return $this->protectedIntake($company,$provider,$listing,false);
    }
    private function protectedIntake(int $company,string $provider,array $listing,bool $onlyNew): ?string
    {
        if(!$this->db->pdo->inTransaction())return $this->db->transaction(fn()=>$this->protectedIntake($company,$provider,$listing,$onlyNew));
        $this->db->run('SAVEPOINT inventory_new_listing');
        $key=$this->writeListing($company,$provider,$listing,$onlyNew,true);
        if($key===null)$this->db->run('ROLLBACK TO SAVEPOINT inventory_new_listing');
        $this->db->run('RELEASE SAVEPOINT inventory_new_listing');
        return $key;
    }
    private function writeListing(int $company,string $provider,array $listing,bool $onlyNew,bool $preserveProduct=false): ?string
    {
        if(!in_array($provider,self::PROVIDERS,true)||!is_string($listing['id']??null)||trim($listing['id'])===''||strlen($listing['id'])>180||trim($listing['title']??'')===''||mb_strlen($listing['title'])>500||strlen($listing['sku']??'')>180)throw new \InvalidArgumentException('Katalog kimliğini kontrol edin.');
        $quantity=$listing['quantity']??null;
        if($quantity!==null&&(!is_int($quantity)||$quantity<0||$quantity>100000000))throw new \InvalidArgumentException('Kaynak stok miktarı doğrulanamadı.');
        $source=hash('sha256',$listing['id']);$now=gmdate('Y-m-d\TH:i:s\Z');
        $write=function()use($company,$provider,$listing,$quantity,$source,$now,$onlyNew,$preserveProduct){
            // Serialize concurrent catalog imports for this tenant, without touching integration settings.
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \OutOfBoundsException('Müşteri bulunamadı.');
            $old=$this->db->one('SELECT * FROM inventory_listings WHERE company_id=? AND provider=? AND listing_key=?',[$company,$provider,$source]);
            if($onlyNew&&$old)return null;
            $evidence=$old?json_decode($old['evidence'],true,32,JSON_THROW_ON_ERROR):['observations'=>[],'periods'=>[]];
            $sku=trim($listing['sku']??'');
            $evidence['sellerSkuCaptured']=true;
            $barcode=self::validGtin(trim($listing['barcode']??''))?trim($listing['barcode']):'';
            $resolved=$sku!=='';
            $key=$resolved?InventoryLiveSkuIdentity::load($this->db,$company)->key($sku):InventoryLiveSkuIdentity::pendingKey($provider,$source);
            $evidence['sourceTitle']=$listing['title'];
            if($provider==='Hepsiburada'){
                $sourceId=$listing['sourceCatalogId']??'';
                if($sourceId!==($evidence['sourceCatalogId']??''))unset($evidence['metadataBarcode']);
                elseif($barcode==='')$barcode=$evidence['metadataBarcode']??'';
                $evidence['sourceCatalogId']=$sourceId;
            }
            $evidence['skuConfirmed']=(int)$resolved;
            unset($evidence['barcodeConfirmed']);
            if($onlyNew&&(new InventoryVisibility($this->db))->removed($company,$key))return null;
            if($resolved&&(!$preserveProduct||!$this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$key])))
                $this->saveProduct($company,$key,$listing['title'],$sku,$barcode,$now,strict:false,verifiedSku:true);
            // Keep daily maximum: a later zero must not erase a positive observation on the same day.
            if($quantity!==null){
                $observations=[];$cutoff=InventoryCatalogEligibility::windowStart(new \DateTimeImmutable($now))->getTimestamp();
                foreach($evidence['observations'] as $item)if(strtotime($item['at'])>=$cutoff)$observations[substr($item['at'],0,10)]=$item;
                $day=substr($now,0,10);
                if(!isset($observations[$day])||$quantity>=$observations[$day]['quantity'])$observations[$day]=['at'=>$now,'quantity'=>$quantity];
                $evidence['observations']=array_values($observations);
            }
            $evidence['identityVerified']=$resolved;
            $evidence['invoiceCatalogId']=$listing['invoiceCatalogId']??$listing['sourceCatalogId']??'';
            if(isset($listing['invoiceModel']))$evidence['invoiceModel']=$listing['invoiceModel'];
            $evidence['model']=$listing['model']??'';
            $evidence['barcode']=$barcode;
            $evidence['brand']=$listing['brand']??'';
            $evidence['imageCandidates']=array_values(array_filter(array_slice(($listing['imageCandidates']??[])?:($evidence['imageCandidates']??[]),0,5),[InventoryImageCandidates::class,'valid']));
            if(is_string($listing['imageBrand']??null)&&trim($listing['imageBrand'])!=='')$evidence['imageBrand']=mb_substr($listing['imageBrand'],0,180);
            $evidence['saleActive']=is_bool($listing['saleActive']??null)?(int)$listing['saleActive']:null;
            $panel=$evidence['panelObservation']??null;
            if($panel&&!isset($panel['invalidatedAt'])&&$panel['observedAt']>time()-InventoryPanelObservation::MAX_AGE){
                $snapshot=['companyId'=>$company,'provider'=>$provider,'listingId'=>$listing['id'],'sku'=>$listing['sku']??'',
                    'barcode'=>$listing['id'],'title'=>$listing['title'],'quantity'=>$quantity];
                if(!InventoryPanelObservation::current($snapshot,$panel,time()))$evidence['panelObservation']['invalidatedAt']=time();
            }
            $evidence['offerPriceCents']=$listing['offerPriceCents']??null;
            if($resolved)$this->saveAliases($company,$provider,$key,[$sku]);
            $json=json_encode($evidence,JSON_THROW_ON_ERROR);
            if($old)$this->db->run('UPDATE inventory_listings SET product_key=?,sku=?,evidence=?,source_quantity=?,observed_at=? WHERE company_id=? AND provider=? AND listing_key=?',[$key,$sku,$json,$quantity,$now,$company,$provider,$source]);
            else $this->db->run('INSERT INTO inventory_listings(company_id,provider,listing_key,remote_id,product_key,sku,evidence,source_quantity,observed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$company,$provider,$source,$listing['id'],$key,$sku,$json,$quantity,$now]);
            return $key;
        };
        return $this->db->pdo->inTransaction()?$write():$this->db->transaction($write);
    }

    public function saveProduct(int $company,string $key,string $title,string $sku,string $barcode,string $now,bool $strict=true,bool $verifiedSku=false):void
    {
        $product=$this->db->one('SELECT * FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$key]);
        if(!$product){
            $this->db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$key,$title,$sku,$barcode,'',$now]);
            InventoryProductPosition::remember($this->db,$company,$key);
            (new InventoryImageQueue($this->db))->enqueue($company,$key);
            return;
        }
        $different=$barcode!==''&&$product['barcode']!==''&&InventoryBarcodeIdentity::code($barcode)!==InventoryBarcodeIdentity::code($product['barcode']);
        if($different&&self::barcodeConflict($product,$sku,$barcode,$strict,$verifiedSku))throw new \DomainException('Ürün barkod eşleşmesi farklı; ürün bilgilerini kontrol edin.');
        $placeholder=str_starts_with($product['title'],'Stok kodu:');
        if(($barcode!==''&&!$different)||$placeholder)
            $this->db->run('UPDATE inventory_products SET title=?,barcode=? WHERE company_id=? AND product_key=?',
                [str_starts_with($title,'Stok kodu:')||($verifiedSku&&!$placeholder)?$product['title']:$title,
                    $different?$product['barcode']:($barcode?:$product['barcode']),$company,$key]);
    }

    private static function barcodeConflict(array $product,string $sku,string $barcode,bool $strict,bool $verifiedSku): bool
    {
        $sameSku=trim($sku)!==''&&mb_strtoupper(trim($product['sku']))===mb_strtoupper(trim($sku));
        return $barcode!==''&&$product['barcode']!==''&&InventoryBarcodeIdentity::code($barcode)!==InventoryBarcodeIdentity::code($product['barcode'])
            &&($strict||(!$sameSku&&!$verifiedSku));
    }

    public function saveAliases(int $company,string $provider,string $key,array $aliases):void
    {
        foreach(array_unique($aliases) as $alias){
            $alias=trim((string)$alias);
            if($alias==='')continue;
            $hash=hash('sha256',mb_strtoupper($alias));
            if(!$this->db->one('SELECT product_key FROM inventory_aliases WHERE company_id=? AND provider=? AND alias_key=? AND product_key=?',[$company,$provider,$hash,$key]))
                $this->db->run('INSERT INTO inventory_aliases(company_id,provider,alias_key,product_key) VALUES(?,?,?,?)',[$company,$provider,$hash,$key]);
        }
    }
    public function matchSku(int $company,string $provider,string $sku): ?string
    {
        if(!in_array($provider,self::PROVIDERS,true)||trim($sku)==='')return null;
        $key=InventoryLiveSkuIdentity::load($this->db,$company)->key($sku);
        $code=InventoryLiveSkuIdentity::normalize($sku);
        foreach($this->db->all('SELECT sku FROM inventory_listings WHERE company_id=? AND provider=? AND product_key=?',[$company,$provider,$key]) as $row)
            if(InventoryLiveSkuIdentity::normalize($row['sku'])===$code)return $key;
        return null;
    }
}
