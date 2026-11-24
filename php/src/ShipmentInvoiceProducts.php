<?php
declare(strict_types=1);
namespace Omnigo;

/** Compatibility metadata for consumers of the product summaries. */
final class ShipmentInvoiceProducts
{
    public static function identities(array $rows):array
    {
        $identities=[];
        foreach($rows as $id=>$sources){
            $codes=[];
            foreach($sources as [$provider,$line]){
                $sku=ShipmentProductCode::sourceCode($provider,$line);
                if($sku!=='')$codes[InventoryLiveSkuIdentity::skuKey($sku)]=true;
            }
            if(count($codes)===1)$identities[$id]=array_key_first($codes);
        }
        return $identities;
    }
}
