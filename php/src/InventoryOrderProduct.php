<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryOrderProduct
{
    public function __construct(private Database $db){}
    public function resolve(int $company,array $line):string
    {
        $sku=InventoryLiveSkuIdentity::load($this->db,$company);
        if($sku){
            $matched=$sku->resolveOrder($line);
            if($matched!==null)return $matched;
        }else{
            if($strict=InventoryBarcodeIdentity::load($this->db,$company))return $strict->resolveOrder($line);
            $identity=new InventorySkuIdentity($this->db);$aliases=$identity->aliasKeys($company,$line['provider'],$line['sku']);
            $matched=$identity->orderKey($company,$aliases,(string)($line['title']??''));
            if($matched!==null)return $matched;
            if(count($aliases)>1)throw new \DomainException('Stok kodu birden fazla ürünle eşleşiyor: '.$line['sku']);
        }
        return (new InventoryCatalog($this->db))->ingest($company,$line['provider'],['id'=>'order-sku:'.hash('sha256',$line['sku']),'sku'=>$line['sku'],'title'=>$line['title']?:'Stok kodu: '.$line['sku'],'quantity'=>null]);
    }
}
