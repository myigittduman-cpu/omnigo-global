<?php
declare(strict_types=1);
namespace Omnigo;

/** A tenant-owned manual overlay; catalogue snapshots remain immutable source facts. */
final class InventorySharedStock
{
    public function __construct(private Database $db){}
    public function forKeys(int $company,array $keys):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        foreach($this->db->all('SELECT * FROM inventory_shared_stock WHERE company_id=? AND product_key IN ('.$in.')',[$company,...$keys]) as $row)$result[$row['product_key']]=$row;
        return $result;
    }
    public static function calculate(array $snapshots,array $manual=[]):array
    {
        $selection=InventoryReferenceStock::resolve($snapshots);$reference=$selection['reference'];
        $base=$reference!==null?(int)$reference['quantity']:(!$snapshots&&isset($manual['manual_quantity'])?(int)$manual['manual_quantity']:null);
        $adjustment=(int)($manual['adjustment']??0);$quantity=$base===null?null:$base+$adjustment;
        $allCurrent=$snapshots&&!array_filter($snapshots,static fn($source)=>($source['health']??'')!=='current'||$source['quantity']===null);
        $verified=(!$snapshots||($allCurrent&&!$selection['different']))?$quantity:null;
        $sourceVersion=$base===null?null:hash('sha256',json_encode([$reference['provider']??'manual',$reference['sku']??'', $base],JSON_THROW_ON_ERROR));
        return ['quantity'=>$quantity,'stockQuantity'=>$quantity,'storeQuantity'=>null,'totalQuantity'=>$quantity,'verifiedTotalQuantity'=>$verified,
            'marketplaceQuantity'=>$reference===null?null:(int)$reference['quantity'],'marketplaceReference'=>$reference,'marketplaceStockDifferent'=>$selection['different'],
            'marketplaceHealth'=>$reference!==null?($selection['different']?'reference':'current'):($snapshots?'unverified':'unlinked'),
            'sourceVersion'=>$sourceVersion,'critical'=>$verified!==null&&$verified<=0];
    }
}
