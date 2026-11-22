<?php
declare(strict_types=1);
namespace Omnigo;

/** Local seller-catalog proof only. A repeated SKU or a title by itself cannot pick a cost. */
final class ProfitabilityProductIdentity
{
    public function __construct(private Database $db) {}

    public function key(int $company,string $provider,array $line,string $sku): ?string
    {
        $evidence=ShipmentSourceProduct::stored($provider,$line);
        $remote=$evidence['listingId']??'';
        $catalog=$evidence['catalogId']??'';
        $gtin=$evidence['barcode']??'';
        $model=$evidence['model']??'';
        if($remote===''&&$catalog===''&&$gtin===''&&$model==='')return null;
        $catalogId=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(l.evidence,'$.invoiceCatalogId'))":"JSON_EXTRACT(l.evidence,'$.invoiceCatalogId')";
        $oldId=str_replace('invoiceCatalogId','sourceCatalogId',$catalogId);
        $rows=$this->db->all("SELECT l.remote_id,l.sku,l.evidence,p.barcode,
                    COALESCE(k.product_key,l.product_key) AS product_key
            FROM inventory_listings l
            JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key
            LEFT JOIN inventory_links k ON k.company_id=l.company_id AND k.provider=l.provider
                AND k.source_product=l.product_key AND k.state='active'
            WHERE l.company_id=? AND l.provider=? AND l.remote_id NOT LIKE 'order-sku:%'
                AND (l.listing_key=? OR COALESCE(NULLIF($catalogId,''),$oldId)=? OR l.listing_key=? OR l.sku=?)
            LIMIT 101",
            [$company,$provider,hash('sha256',$remote),$catalog,hash('sha256',$catalog),$sku]);
        if(count($rows)>100)return null;
        $matches=[];
        foreach($rows as $row){
            $listing=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
            if($sku!==''&&InventoryLiveSkuIdentity::normalize($row['sku'])!==InventoryLiveSkuIdentity::normalize($sku))continue;
            $listingGtin=InventoryProductMatch::barcode((string)(($listing['barcode']??'')?:($listing['metadataBarcode']??$row['barcode'])));
            $listingModel=InventoryLiveSkuIdentity::normalize((string)(($listing['invoiceModel']??'')?:($listing['model']??'')));
            if(($gtin!==''&&$listingGtin!==''&&$gtin!==$listingGtin)||($model!==''&&$listingModel!==''&&$model!==$listingModel))continue;
            $listingCatalog=($listing['invoiceCatalogId']??'')?:($listing['sourceCatalogId']??'');
            $exactListing=$remote!==''&&$row['remote_id']===$remote;
            $exactCatalog=$catalog!==''&&($row['remote_id']===$catalog||$listingCatalog===$catalog);
            $exactGtin=$gtin!==''&&$gtin===$listingGtin;
            $exactModel=$model!==''&&$model===$listingModel&&$sku!=='';
            // A catalog reference may cover variants; require corroboration when the order specifies a variant.
            if(isset($evidence['variantId'])&&!$exactGtin&&!$exactModel)continue;
            if($exactListing||$exactGtin||$exactModel||($exactCatalog&&$sku!==''))$matches[$row['product_key']]=true;
        }
        return count($matches)===1?array_key_first($matches):null;
    }
}
