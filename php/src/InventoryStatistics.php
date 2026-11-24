<?php
declare(strict_types=1);
namespace Omnigo;

/** Statistics follow the shared SKU card without moving or modifying source order records. */
final class InventoryStatistics
{
    public function __construct(private Database $db){}
    /** Store sales are reported separately; marketplace stock is never changed here. */
    public function storeSalesForKeys(int $company,array $keys,bool $includeReturns=true):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        $kinds=$includeReturns?"('store_sale','store_return','horoz_sale','horoz_return')":"('store_sale','horoz_sale')";
        $card=InventoryStockCard::productKeySql($this->db,'l');
        $rows=$this->db->all("SELECT $card AS product_key,SUM(l.delta)*-1 sold FROM inventory_ledger l
            WHERE l.company_id=? AND l.kind IN $kinds AND $card IN ($in) GROUP BY $card",[$company,...$keys]);
        foreach($rows as $row)$result[$row['product_key']]=(int)$row['sold'];
        return $result;
    }
    public function forKeys(int $company,array $keys,?array $visibility=null):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        $card=InventoryStockCard::productKeySql($this->db,'b');
        $rows=$this->db->all("SELECT $card target,b.provider,SUM(b.ordered) gross,SUM(b.cancelled) cancelled,SUM(b.returned) returned,
            SUM(CASE WHEN b.invoice_cents IS NOT NULL AND b.ordered>0 THEN b.invoice_cents ELSE 0 END) invoice_cents,
            SUM(CASE WHEN b.invoice_cents IS NOT NULL THEN b.ordered ELSE 0 END) priced_units FROM inventory_balances b
            WHERE b.company_id=? AND $card IN ($in) GROUP BY $card,b.provider",[$company,...$keys]);
        foreach($rows as $row){
            $gross=(int)$row['gross'];$cancelled=(int)$row['cancelled'];$returned=(int)$row['returned'];
            $net=$gross-(($visibility['cancellations']??true)?$cancelled:0)-(($visibility['returns']??true)?$returned:0);
            $result[$row['target']][]=['provider'=>$row['provider'],'gross'=>$gross,'cancelled'=>$cancelled,'returned'=>$returned,'net'=>$net,'averageInvoiceCents'=>(int)$row['priced_units']>0?(int)round((float)$row['invoice_cents']/(int)$row['priced_units']):null,'unpricedUnits'=>$gross-(int)$row['priced_units']];
        }
        return $result;
    }
}
