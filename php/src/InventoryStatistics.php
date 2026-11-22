<?php
declare(strict_types=1);
namespace Omnigo;

/** Statistics follow verified links without moving or modifying source order records. */
final class InventoryStatistics
{
    public function __construct(private Database $db){}
    /** Store sales are reported separately; marketplace stock is never changed here. */
    public function storeSalesForKeys(int $company,array $keys,bool $includeReturns=true):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        $kinds=$includeReturns?"('store_sale','store_return','horoz_sale','horoz_return')":"('store_sale','horoz_sale')";
        $rows=$this->db->all('SELECT product_key,SUM(-delta) sold FROM inventory_ledger WHERE company_id=? AND kind IN '.$kinds.' AND product_key IN ('.$in.') GROUP BY product_key',[$company,...$keys]);
        foreach($rows as $row)$result[$row['product_key']]=(int)$row['sold'];
        return $result;
    }
    public function forKeys(int $company,array $keys,?array $visibility=null):array
    {
        if(!$keys)return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        $rows=$this->db->all("SELECT COALESCE(k.product_key,b.product_key) target,b.provider,SUM(b.ordered) gross,SUM(b.cancelled) cancelled,SUM(b.returned) returned,SUM(b.ordered-b.cancelled-b.returned) net,SUM(CASE WHEN b.invoice_cents IS NOT NULL AND b.ordered>0 THEN b.invoice_cents ELSE 0 END) invoice_cents,SUM(CASE WHEN b.invoice_cents IS NOT NULL THEN b.ordered ELSE 0 END) priced_units FROM inventory_balances b LEFT JOIN inventory_links k ON k.company_id=b.company_id AND k.provider=b.provider AND k.source_product=b.product_key AND k.state='active' WHERE b.company_id=? AND COALESCE(k.product_key,b.product_key) IN (".$in.') GROUP BY COALESCE(k.product_key,b.product_key),b.provider',[$company,...$keys]);
        foreach($rows as $row){
            $gross=(int)$row['gross'];$cancelled=(int)$row['cancelled'];$returned=(int)$row['returned'];
            $net=$gross-(($visibility['cancellations']??true)?$cancelled:0)-(($visibility['returns']??true)?$returned:0);
            $result[$row['target']][]=['provider'=>$row['provider'],'gross'=>$gross,'cancelled'=>$cancelled,'returned'=>$returned,'net'=>$net,'averageInvoiceCents'=>(int)$row['priced_units']>0?(int)round((float)$row['invoice_cents']/(int)$row['priced_units']):null,'unpricedUnits'=>$gross-(int)$row['priced_units']];
        }
        return $result;
    }
}
