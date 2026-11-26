<?php
declare(strict_types=1);
namespace Omnigo;

/** Resolve batch targets from fresh listing rows without widening the merchant scope. */
final class ActiveMarketplaceListingTargets
{
    private const PROVIDERS=['Hepsiburada'=>'hepsiburada','n11'=>'n11','Trendyol'=>'trendyol','Pazarama'=>'pazarama'];
    public function __construct(private Database $db) {}

    public function forRows(int $company,array $rows,array $cutoffs=[]): array
    {
        $eligible=array_filter($rows,fn($r)=>isset(self::PROVIDERS[$r['provider']],$r['local_product'])&&trim($r['sku'])!==''&&trim($r['remote_id'])!==''&&ActiveMarketplaceListings::quantity($r)!==null);
        if(!$eligible||!IntegrationImportSchema::tableAvailable($this->db,'marketplace_stock_requests','company_id'))return [];
        $keys=array_values(array_unique(array_column($eligible,'listing_key')));
        $skus=array_values(array_unique(array_column($eligible,'sku')));
        $slots=implode(',',array_fill(0,count($keys),'?'));
        $skuSlots=implode(',',array_fill(0,count($skus),'?'));
        $identities=[];$skuCounts=[];
        $listings=$this->db->all("SELECT listing_key,product_key,provider,sku,observed_at FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%' AND (listing_key IN ($slots) OR sku IN ($skuSlots))",[$company,...$keys,...$skus]);
        foreach($listings as $listing){
            $identity=$listing['product_key'].':'.$listing['listing_key'];
            $identities[$identity]=1+($identities[$identity]??0);
            // A historical offer cannot collide with a SKU in the verified catalogue pass.
            // Keep listing-key collisions across providers guarded independently above.
            if(isset($cutoffs[$listing['provider']])&&strcmp($listing['observed_at'],$cutoffs[$listing['provider']])<0)continue;
            $sku=$listing['provider'].':'.mb_strtoupper($listing['sku'],'UTF-8');
            $skuCounts[$sku]=1+($skuCounts[$sku]??0);
        }
        $requests=$this->db->all("SELECT request_id,listing_key FROM marketplace_stock_requests WHERE company_id=? AND listing_key IN ($slots) AND state NOT IN ('succeeded','failed')",[$company,...$keys]);
        $pending=[];
        foreach($requests as $request){
            $id=(string)$request['request_id'];
            $provider=str_starts_with($id,'pilot:')?(explode(':',$id,3)[1]??''):'hepsiburada';
            $pending[$provider][$request['listing_key']]=$id;
        }
        $targets=[];
        foreach($eligible as $index=>$row){
            $key=$row['listing_key'];$product=$row['product_key'];$provider=$row['provider'];$remote=$row['remote_id'];$sku=$row['sku'];
            if(($identities[$product.':'.$key]??0)!==1||($skuCounts[$provider.':'.mb_strtoupper($sku,'UTF-8')]??0)!==1)continue;
            $identity=[];
            if($provider==='Hepsiburada'){
                $catalogSku=HepsiburadaStockIdentity::catalogSku($row);
                if($catalogSku===null)continue;
                $identity=['catalogSku'=>$catalogSku,'identityToken'=>HepsiburadaStockIdentity::confirmation($company,$row,$catalogSku)];
            }
            $price=(json_decode($row['evidence']??'{}',true)?:[])['offerPriceCents']??null;
            $targets[$index]=['priceCents'=>is_int($price)&&$price>0?$price:null,'key'=>$key,'productKey'=>$product,'sku'=>$sku,'provider'=>$provider,'quantity'=>ActiveMarketplaceListings::quantity($row),
                'remoteId'=>$remote,'requestId'=>$pending[self::PROVIDERS[$provider]][$key]??null]+$identity;
        }
        return $targets;
    }
}
