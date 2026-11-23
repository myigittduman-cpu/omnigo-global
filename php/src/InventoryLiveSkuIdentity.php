<?php
declare(strict_types=1);
namespace Omnigo;

/** Live catalogue identity for the shared stock model. */
final class InventoryLiveSkuIdentity
{
    public const POLICY='inventoryStockCodeIdentity';

    public function __construct(private Database $db,private int $company,private array $keys,private array $conflicts=[]){}

    public static function load(Database $db,int $company):?self
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        $profile=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $policy=$profile[self::POLICY]??[];
        return ($profile['workspacePolicy']['inventory']['mode']??'')==='horoz'&&($policy['version']??null)===1
            ?new self($db,$company,$policy['keys']??[],$policy['conflicts']??[]):null;
    }

    public static function normalize(string $sku):string
    {
        return mb_strtoupper(trim($sku),'UTF-8');
    }

    public static function skuKey(string $sku):string
    {
        return hash('sha256','stock-code:'.self::normalize($sku));
    }

    /** PTT variant rows in the legacy feed stored the barcode in the SKU field. */
    public static function listingSku(string $provider,string $remoteId,string $sku):string
    {
        if($provider==='PTT AVM'&&str_contains($remoteId,':')&&$sku===substr($remoteId,strpos($remoteId,':')+1))return '';
        return trim($sku);
    }

    public function key(string $sku):string
    {
        $key=self::skuKey($sku);
        return $this->keys[$key]??$key;
    }

    public function ambiguous(string $sku):bool
    {
        return isset($this->conflicts[self::skuKey($sku)]);
    }

    public function conflictCount():int{return count($this->conflicts);}

    public function listingKey(string $sku,string $title,string $barcode,?array $previous):?string
    {
        $key=$this->key($sku);
        $name=InventoryProductMatch::name($title);
        $gtin=InventoryProductMatch::barcode($barcode);
        $product=$this->db->one('SELECT title,barcode FROM inventory_products WHERE company_id=? AND product_key=?',[$this->company,$key]);
        if($product&&$name!==''&&$gtin!==''&&!$this->ambiguous($sku)){
            $oldName=InventoryProductMatch::name($product['title']);
            $oldGtin=InventoryProductMatch::barcode($product['barcode']);
            if($oldName!==''&&$oldGtin!==''&&InventoryProductMatch::conflicts([[
                'sku'=>self::normalize($sku),'barcodes'=>[$gtin=>true,$oldGtin=>true],'names'=>[$name=>true,$oldName=>true],
            ]]))$this->rememberConflict($sku);
        }
        if($this->ambiguous($sku)){
            if($previous&&self::normalize($previous['sku'])===self::normalize($sku)
                &&($old=$this->db->one('SELECT barcode FROM inventory_products WHERE company_id=? AND product_key=?',[$this->company,$previous['product_key']]))){
                $oldGtin=InventoryProductMatch::barcode($old['barcode']);
                if($gtin===''||$oldGtin===''||$oldGtin===$gtin)return $previous['product_key'];
            }
            if($name===''||$gtin==='')return null;
            $rows=$this->db->all('SELECT DISTINCT p.product_key,p.title,l.sku FROM inventory_products p
                JOIN inventory_listings l ON l.company_id=p.company_id AND l.product_key=p.product_key
                WHERE p.company_id=? AND p.barcode IN (?,?,?) AND '.self::confirmedSql(),
                [$this->company,$barcode,$gtin,ltrim($barcode,'0')]);
            $matches=[];
            foreach($rows as $row)if(self::normalize($row['sku'])===self::normalize($sku)&&InventoryProductMatch::name($row['title'])===$name)
                $matches[$row['product_key']]=true;
            if(count($matches)===1)return array_key_first($matches);
            return $matches?null:hash('sha256','sku-variant:'.self::skuKey($sku).':'.$gtin);
        }
        if($product||$key!==self::skuKey($sku)||$name===''||$gtin==='')return $key;
        // The GTIN and model must agree even if the sellers worded the titles differently.
        $cardKey=InventoryStockCard::listingKeySql();
        $sourceBarcode="JSON_EXTRACT(l.evidence,'$.barcode')";
        if($this->db->mysql())$sourceBarcode="JSON_UNQUOTE($sourceBarcode)";
        $rows=$this->db->all('SELECT DISTINCT p.product_key,p.title FROM inventory_listings l'.InventoryStockCard::listingLinkSql().'
            JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key='.$cardKey.'
            WHERE l.company_id=? AND p.barcode IN (?,?,?) AND '.self::confirmedSql()."
            AND $sourceBarcode IN (?,?,?)",[$this->company,$barcode,$gtin,ltrim($barcode,'0'),$barcode,$gtin,ltrim($barcode,'0')]);
        $matches=array_values(array_filter($rows,static fn($row)=>InventoryProductMatch::sameModel($row['title'],$title)));
        if(count($matches)!==1)return $key;
        $target=$matches[0]['product_key'];
        $this->rememberKey($sku,$target);
        return $target;
    }

    /** Catalogue and manual product writes already hold the tenant lock. */
    public function rememberKey(string $sku,string $productKey):void
    {
        $key=self::skuKey($sku);
        $this->updatePolicy('keys',$key,$productKey);
        $this->keys[$key]=$productKey;
    }

    private function rememberConflict(string $sku):void
    {
        $key=self::skuKey($sku);
        $this->updatePolicy('conflicts',$key,true);
        $this->conflicts[$key]=true;
    }

    private function updatePolicy(string $field,string $key,string|bool $value):void
    {
        $row=$this->db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$this->company]);
        $profile=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        $profile[self::POLICY][$field][$key]=$value;
        $this->db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',
            [json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$this->company]);
    }

    public static function confirmedSql():string
    {
        return "l.remote_id NOT LIKE 'order-sku:%' AND JSON_EXTRACT(l.evidence,'$.skuConfirmed')=1";
    }

    public function pendingListings():array
    {
        return array_map(static fn(array $row)=>['provider'=>$row['provider'],'count'=>(int)$row['n']],
            $this->db->all("SELECT l.provider,COUNT(*) n FROM inventory_listings l WHERE l.company_id=?
                AND l.remote_id NOT LIKE 'order-sku:%' AND COALESCE(JSON_EXTRACT(l.evidence,'$.skuConfirmed'),0)=0
                GROUP BY l.provider ORDER BY l.provider",[$this->company]));
    }

    public function resolveOrder(array $line):?string
    {
        $prior=$this->db->one('SELECT product_key FROM inventory_balances WHERE company_id=? AND provider=? AND line_key=?',
            [$this->company,$line['provider'],$line['lineKey']]);
        if($prior)return $prior['product_key'];
        $sku=self::normalize($line['sku']);
        if($sku==='')throw new \DomainException('Siparişin stok kodu doğrulanamadı.');
        if($this->ambiguous($sku)){
            $rows=$this->db->all('SELECT l.sku,l.product_key FROM inventory_listings l WHERE l.company_id=? AND l.provider=? AND '.self::confirmedSql(),[$this->company,$line['provider']]);
            $keys=[];
            foreach($rows as $row)if(self::normalize($row['sku'])===$sku)$keys[$row['product_key']]=true;
            if(count($keys)===1)return array_key_first($keys);
            throw new \DomainException('Stok kodu birden fazla ürünle ilişkili. Kaynak ürün kodunu kontrol edin.');
        }
        $key=$this->key($sku);
        return $this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$this->company,$key])?$key:null;
    }
}
