<?php
declare(strict_types=1);
namespace Omnigo;

/** Values come from the merchant's authenticated product list. API evidence remains authoritative. */
final class InventoryPanelObservation
{
    public const MAX_AGE = 1800;

    public static function make(array $listing,array $report,int $now): ?array
    {
        if(!in_array($listing['provider'],['Pazarama','idefix'],true))return null;
        foreach(['companyId','provider','sku','barcode','title','quantity'] as $key){
            if(!isset($listing[$key],$report[$key])||$listing[$key]!==$report[$key])return null;
        }
        if(!is_int($report['companyId'])||$report['companyId']<1||!is_int($report['quantity'])||$report['quantity']<0)return null;
        foreach(['sku','barcode','title'] as $key)if(!is_string($report[$key])||trim($report[$key])==='')return null;
        // The API's listing key is the marketplace barcode on both of these providers.
        if($listing['listingId']!==$report['barcode'])return null;
        $checked=$report['observedAt']??null;
        if(!is_int($checked)||$checked>$now||$checked<=$now-self::MAX_AGE)return null;
        $status=match($report['label']??''){
            'Satışta Olan','Satışta','Satışa Açık'=>'on_sale',
            'Satışa Kapalı'=>'off_sale', 'Satış Dışı'=>'not_listed',
            'Stoğu olmayan','Tükendi','Stokta Yok'=>'out_of_stock',
            default=>null
        };
        if($status===null||($status==='out_of_stock'&&$report['quantity']!==0))return null;
        return ['source'=>'merchant_panel','status'=>$status,'label'=>$report['label'],
            'observedAt'=>$checked,'fingerprint'=>self::fingerprint($listing)];
    }

    public static function current(array $listing,?array $saved,int $now): ?array
    {
        if(!$saved||isset($saved['invalidatedAt'])||$saved['observedAt']>$now||$saved['observedAt']<=$now-self::MAX_AGE)return null;
        return hash_equals($saved['fingerprint'],self::fingerprint($listing))?$saved:null;
    }

    private static function fingerprint(array $listing):string
    {
        $parts=[];
        foreach(['companyId','provider','listingId','sku','barcode','title','quantity'] as $field)$parts[]=$listing[$field]??null;
        return hash('sha256',json_encode($parts,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}
