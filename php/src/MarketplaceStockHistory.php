<?php
declare(strict_types=1);
namespace Omnigo;

/** Non-financial outbox events appear beside the ledger without changing stock arithmetic. */
final class MarketplaceStockHistory
{
    public static function hidePreviousRetries(Database $db,int $company,array $rows):array
    {
        $children=[];foreach($rows as $index=>$row)if(isset($row['marketplaceRequest']))$children[hash('sha256','marketplace-stock-retry:'.$company.':'.$row['event_key'])]=$index;
        if(!$children)return $rows;
        $in=implode(',',array_fill(0,count($children),'?'));
        foreach($db->all('SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND request_id IN ('.$in.')',[$company,...array_keys($children)]) as $child)$rows[$children[$child['request_id']]]['marketplaceRequest']['retryable']=false;
        return $rows;
    }
    public static function source():string
    {
        return "(SELECT company_id,event_key,product_key,kind,delta,quantity_after,note,created,actor_id,NULL AS request_state,NULL AS request_error,NULL AS request_quantity,NULL AS request_source,NULL AS request_updated FROM inventory_ledger
            UNION ALL SELECT company_id,request_id AS event_key,product_key,'marketplace_stock_update' AS kind,NULL AS delta,NULL AS quantity_after,NULL AS note,created,actor_id,state AS request_state,error AS request_error,quantity AS request_quantity,source_quantity AS request_source,updated AS request_updated FROM marketplace_stock_requests) l";
    }
    public static function decorate(array $row):array
    {
        $row['quantity_before']=(int)$row['request_source'];$row['delta']=null;
        $row['quantity_after']=$row['request_state']==='succeeded'?(int)$row['request_quantity']:null;
        $origin=MarketplaceStockJob::origin($row['event_key']);
        $row['marketplaceRequest']=['id'=>$row['event_key'],'state'=>$row['request_state'],'quantity'=>(int)$row['request_quantity'],'provider'=>MarketplaceStockJob::CHANNELS[$origin??'hepsiburada'],'error'=>$row['request_error'],'updatedAt'=>$row['request_updated'],'retryable'=>$origin===null&&in_array($row['request_state'],['failed','uncertain'],true)];
        return $row;
    }
}
