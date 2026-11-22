<?php
declare(strict_types=1);
namespace Omnigo;

/** Hepsiburada identifies the offer by listingId and the catalog product by hepsiburadaSku. */
final class HepsiburadaStockIdentity
{
    public static function catalogSku(array $row): ?string
    {
        $evidence=json_decode($row['evidence'],true,64);
        if(!is_array($evidence))return null;
        $source=$evidence['sourceCatalogId']??'';
        $invoice=$evidence['invoiceCatalogId']??'';
        if(!is_string($source)||!is_string($invoice)||($source!==''&&$invoice!==''&&$source!==$invoice))return null;
        $code=$source!==''?$source:$invoice;
        if($code===''||trim($code)!==$code||strlen($code)>180||preg_match('/[[:cntrl:]]/',$code))return null;
        if($row['remote_id']===$code||hash('sha256',$row['remote_id'])!==$row['listing_key'])return null;
        return $code;
    }

    public static function confirmation(int $company,array $row,string $catalogSku):string
    {
        return hash('sha256',json_encode([$company,$row['listing_key'],$row['product_key'],$row['sku'],$catalogSku],JSON_THROW_ON_ERROR));
    }

    public static function matchesRead(array $job,array $listing):bool
    {
        $listingId=$listing['listingId']??null;
        return is_string($listingId)&&$listingId!==''&&hash('sha256',$listingId)===$job['listing_key']
            &&($listing['hepsiburadaSku']??null)===$job['remote_id'];
    }
}
