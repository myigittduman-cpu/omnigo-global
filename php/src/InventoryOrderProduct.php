<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryOrderProduct
{
    public function __construct(private Database $db){}
    public function resolve(int $company,array $line):string
    {
        $matched=InventoryLiveSkuIdentity::load($this->db,$company)->resolveOrder($line);
        if($matched!==null)return $matched;
        $sku=$line['sellerStockCode']??$line['sku'];
        return (new InventoryCatalog($this->db))->ingest($company,$line['provider'],[
            'id'=>'order-sku:'.InventoryLiveSkuIdentity::skuKey($sku),
            'sku'=>$sku,'title'=>($line['title']??'')?:'Stok kodu: '.$sku,'quantity'=>null,
        ]);
    }
}
