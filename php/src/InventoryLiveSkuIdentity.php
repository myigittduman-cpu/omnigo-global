<?php
declare(strict_types=1);
namespace Omnigo;

/** SKU ownership is scoped to a store. The saved keys preserve existing product references. */
final class InventoryLiveSkuIdentity
{
    public const POLICY='inventoryStockCodeIdentity';
    public const VERSION=2;

    public function __construct(private Database $db,private int $company,private array $keys=[]){}

    public static function load(Database $db,int $company):self
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        $profile=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $policy=$profile[self::POLICY]??[];
        return new self($db,$company,($policy['version']??null)===self::VERSION?($policy['keys']??[]):[]);
    }

    public static function normalize(string $sku):string
    {
        return mb_strtoupper(trim($sku),'UTF-8');
    }

    public static function skuKey(string $sku):string
    {
        return hash('sha256','stock-code-v2:'.self::normalize($sku));
    }

    /** Old PTT variant snapshots sometimes placed the barcode in the SKU field. */
    public static function listingSku(string $provider,string $remoteId,string $sku):string
    {
        return $provider==='PTT AVM'&&str_contains($remoteId,':')&&trim($sku)===substr($remoteId,strpos($remoteId,':')+1)
            ?'':trim($sku);
    }

    public function key(string $sku):string
    {
        $hash=self::skuKey($sku);
        if(isset($this->keys[$hash]))return $this->keys[$hash];
        if($this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$this->company,$hash]))return $hash;
        return (new InventorySkuIdentity($this->db))->catalogKey($this->company,$sku)??$hash;
    }

    public static function pendingKey(string $provider,string $source):string
    {
        return hash('sha256','pending-stock-code:'.$provider.':'.$source);
    }

    public static function confirmedSql():string
    {
        return "l.remote_id NOT LIKE 'order-sku:%' AND TRIM(l.sku)<>''";
    }

    public function pendingListings():array
    {
        return array_map(static fn(array $row)=>['provider'=>$row['provider'],'count'=>(int)$row['n']],
            $this->db->all("SELECT provider,COUNT(*) n FROM inventory_listings WHERE company_id=?
                AND remote_id NOT LIKE 'order-sku:%' AND TRIM(sku)='' GROUP BY provider ORDER BY provider",[$this->company]));
    }

    public function resolveOrder(array $line):?string
    {
        if(isset($line['lineKey'])){
            $prior=$this->db->one('SELECT product_key FROM inventory_balances WHERE company_id=? AND provider=? AND line_key=?',
                [$this->company,$line['provider'],$line['lineKey']]);
            if($prior)return $prior['product_key'];
        }
        $sku=trim($line['sellerStockCode']??$line['sku']);
        if($sku==='')throw new \DomainException('Siparişin stok kodu doğrulanamadı.');
        $key=$this->key($sku);
        return $this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$this->company,$key])?$key:null;
    }
}
