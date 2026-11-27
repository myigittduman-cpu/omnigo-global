<?php
declare(strict_types=1);
namespace Omnigo;

/** Read the stock code from an exact order line or the same explicit SKU in its packages. */
final class OrderSkuLines
{
    public static function match(string $provider,array $products,array $packages): array
    {
        $byId=$bySku=[];
        foreach($packages as $package)foreach(($package['details']??$package)['products']??[] as $line){
            $id=(string)($line['lineId']??'');
            $sku=ShipmentProductCode::sourceCode($provider,$line);
            if($sku==='')continue;
            $code=InventoryLiveSkuIdentity::normalize($sku);
            if($id!=='')$byId[$id][$code]=$sku;
            $bySku[$code]=$sku;
        }

        $matches=[];
        foreach($products as $index=>$product){
            $explicit=ShipmentProductCode::normalize(trim((string)($product['sellerStockCode']??'')));
            if($explicit!==''){$matches[$index]=$explicit;continue;}
            $id=(string)($product['lineId']??'');
            $code=InventoryLiveSkuIdentity::normalize((string)($product['sku']??''));
            $exact=$byId[$id]??[];
            if(count($exact)===1)$matches[$index]=reset($exact);
            elseif(isset($bySku[$code])&&($id===''||isset($exact[$code])))$matches[$index]=$bySku[$code];
        }
        return $matches;
    }

    public static function annotate(array $items,array $matched): array
    {
        foreach($items as &$item){
            if(!isset($item['products']))continue;
            foreach($item['products'] as $index=>&$product){
                $sku=$matched[$item['id']][$index]??'';
                if($sku==='')continue;
                $product['productCode']=$sku;
                $product['invoiceProductIdentity']=InventoryLiveSkuIdentity::skuKey($sku);
            }
            unset($product);
        }
        unset($item);
        return $items;
    }
}
