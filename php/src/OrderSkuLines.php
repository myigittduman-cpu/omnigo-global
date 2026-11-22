<?php
declare(strict_types=1);
namespace Omnigo;

/** Annotate existing order lines from their own marketplace packages. */
final class OrderSkuLines
{
    public static function match(string $provider,array $products,array $packages): array
    {
        $byId=[];
        $bySignature=[];
        foreach($packages as $package)foreach($package['details']['products']??[] as $line){
            $id=(string)($line['lineId']??'');
            if($id==='')continue;
            $byId[$id][]=$line;
            $signature=self::signature($line,ShipmentProductCode::sourceCode($provider,$line));
            if($signature!==null)$bySignature[$signature][]=$line;
        }

        $matches=[];
        $usage=[];
        foreach($products as $index=>$product){
            $id=(string)($product['lineId']??'');
            $signature=self::signature($product,ShipmentProductCode::normalize($product['sku']??null));
            $candidates=$id!==''?($byId[$id]??[]):($bySignature[$signature??'']??[]);
            if(count($candidates)!==1)continue;
            $line=$candidates[0];
            if(ShipmentProductCode::sourceCode($provider,$line)==='')continue;
            $matches[$index]=$line;
            $usage[$line['lineId']]=($usage[$line['lineId']]??0)+1;
        }
        return array_filter($matches,static fn($line)=>$usage[$line['lineId']]===1);
    }

    private static function signature(array $line,string $code): ?string
    {
        $title=trim((string)($line['title']??''));
        $quantity=filter_var($line['quantity']??null,FILTER_VALIDATE_INT);
        if($code===''||$title===''||$quantity===false||$quantity<1)return null;
        return json_encode([$code,$title,$quantity],JSON_THROW_ON_ERROR);
    }

    public static function annotate(Database $db,int $company,array $items,array $matched): array
    {
        $evidence=[];
        foreach($items as $item)foreach($matched[$item['id']]??[] as $index=>$line){
            $evidence[$item['id'].':'.$index]=[[$item['provider'],$line]];
        }
        $identities=(new ShipmentInvoiceProducts($db))->identities($company,$evidence);
        foreach($items as &$item){
            if(!isset($item['products']))continue;
            foreach($item['products'] as $index=>&$product){
                $line=$matched[$item['id']][$index]??null;
                if($line===null)continue;
                $product['productCode']=ShipmentProductCode::sourceCode($item['provider'],$line);
                $key=$item['id'].':'.$index;
                if(isset($identities[$key]))$product['invoiceProductIdentity']=$identities[$key];
            }
            unset($product);
        }
        unset($item);
        return $items;
    }
}
