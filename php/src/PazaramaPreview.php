<?php
declare(strict_types=1);
namespace Omnigo;

/** An allowlisted sample for reconciliation; never a published sales total. */
final class PazaramaPreview
{
    private const AMOUNTS=['orderAmount','shipmentAmount','discountAmount'];
    public static function map(array $orders): array
    {
        if (count($orders)>20) throw new SyncFailure(422);
        $result=[];$ids=[];
        foreach ($orders as $order) {
            if (!is_array($order) || !is_string($order['orderId']??null)
                || !preg_match('/^[a-f0-9-]{36}$/iD',$order['orderId'])
                || !preg_match('/^\d{1,24}$/D',(string)($order['orderNumber']??''))) throw new SyncFailure(422);
            if (isset($ids[$order['orderId']])) throw new SyncFailure(422);
            $ids[$order['orderId']]=true;
            $date=$order['orderDate']??null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/D',$date)) throw new SyncFailure(422);
            if (!in_array($order['currency']??null,['TL','TRY'],true)) throw new SyncFailure(422);
            $row=['orderNumber'=>(string)$order['orderNumber'],'orderDate'=>$date,'currency'=>'TRY'];
            foreach(self::AMOUNTS as $name) $row[$name.'Cents']=array_key_exists($name,$order)?KoctasOrderMapper::cents($order[$name]):null;
            if ($row['orderAmountCents']===null) throw new SyncFailure(422);
            $row['status']=is_int($order['status']??null)?$order['status']:null;
            // Product lines, names, addresses, tokens and unrecognised fields never leave this method.
            $result[]=$row;
        }
        return $result;
    }
}
