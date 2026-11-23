<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryStockCardConflicts
{
    public function __construct(private Database $db){}

    public function listing(int $company): array
    {
        $identity=InventoryLiveSkuIdentity::load($this->db,$company);
        if(!$identity||!$identity->conflictCount())return [];
        $key=InventoryStockCard::listingKeySql();
        $query=$this->db->pdo->prepare("SELECT DISTINCT $key card_key,l.sku,p.title,p.barcode,l.provider,l.remote_id
            FROM inventory_listings l".InventoryStockCard::listingLinkSql()."
            JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=$key
            WHERE l.company_id=? AND l.remote_id NOT LIKE 'order-sku:%'
            AND NOT EXISTS (SELECT 1 FROM inventory_removed_products r WHERE r.company_id=l.company_id AND r.product_key IN (l.product_key,$key))
            ORDER BY l.sku,p.title,$key,l.provider");
        $query->execute([$company]);
        $cards=[];$codes=[];
        while($record=$query->fetch(\PDO::FETCH_ASSOC)){
            $sku=InventoryLiveSkuIdentity::listingSku($record['provider'],$record['remote_id'],$record['sku']);
            if(!$identity->ambiguous($sku))continue;
            $card=$record['card_key'];$code=InventoryLiveSkuIdentity::normalize($sku);
            $codes[$code][$card]=true;
            $cards[$code][$card]??=['key'=>$card,'sku'=>$sku,'title'=>$record['title'],'barcode'=>$record['barcode'],'providers'=>[]];
            $cards[$code][$card]['providers'][]=$record['provider'];
        }
        $items=[];
        foreach($cards as $code=>$matches){
            if(count($codes[$code])<2)continue;
            foreach($matches as $card){
                $card['providers']=InventoryStockCard::providers(array_map(static fn($provider)=>['provider'=>$provider],$card['providers']));
                $items[]=$card;
            }
        }
        return $items;
    }
}
