<?php
declare(strict_types=1);
namespace Omnigo;

/** Fill a missing PTT code only from an exact, unique product ID in the same tenant. */
final class InventoryOrderIdentity
{
    private array $catalogCodes=[];
    private ?bool $catalogAvailable=null;
    public function __construct(private Database $db){}
    public function enrich(int $company,string $provider,array $order): array
    {
        if($provider!=='PTT AVM')return $order;
        foreach($order['products']??[] as $index=>$product){
            if(trim((string)($product['sku']??''))!=='')continue;
            $code=$this->catalogCode($company,$product['sourceProductId']??null);
            if($code!==null)$order['products'][$index]['sku']=$code;
        }
        return $order;
    }
    public function catalogCode(int $company,mixed $id):?string
    {
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id))return null;
        $this->catalogAvailable??=(bool)$this->db->one($this->db->mysql()
            ?"SHOW TABLES LIKE 'inventory_listings'":"SELECT 1 FROM sqlite_master WHERE type='table' AND name='inventory_listings'");
        if(!$this->catalogAvailable)return null;
        $key=$company.':'.$id;
        if(!array_key_exists($key,$this->catalogCodes)){
            $matches=$this->db->all("SELECT DISTINCT product_key,sku FROM inventory_listings WHERE company_id=? AND provider='PTT AVM' AND remote_id=? LIMIT 2",[$company,$id]);
            $this->catalogCodes[$key]=count($matches)===1&&trim($matches[0]['sku'])!==''?$matches[0]['sku']:null;
        }
        return $this->catalogCodes[$key];
    }
    public function packages(string $provider,array $order,array $packages): array
    {
        if($provider!=='PTT AVM')return $packages;$codes=[];
        foreach($order['products']??[] as $product){
            $line=(string)($product['lineId']??'');$sku=trim((string)($product['sku']??''));if($line===''||$sku==='')continue;
            $value=[$sku,$product['quantity']??null];
            $codes[$line]=array_key_exists($line,$codes)&&$codes[$line]!==$value?null:$value;
        }
        foreach($packages as &$package)foreach($package['products']??[] as $index=>$product){
            $code=$codes[(string)($product['lineId']??'')]??null;
            if(trim((string)($product['sku']??''))===''&&$code&&($product['quantity']??null)===$code[1])$package['products'][$index]['sku']=$code[0];
        }
        unset($package);return $packages;
    }
}
