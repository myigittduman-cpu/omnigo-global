<?php
declare(strict_types=1);
namespace Omnigo;

/** Store financial fields only. Customer names, addresses and contact data are discarded. */
final class KoctasOrderMapper
{
    private const STATES=['STAGING','WAITING_ACCEPTANCE','WAITING_DEBIT','WAITING_DEBIT_PAYMENT','SHIPPING','SHIPPED','TO_COLLECT','RECEIVED','CLOSED','REFUSED','CANCELED'];
    // Mirakl can refund an individual line while the order retains its received/closed state.
    private const LINE_STATES=[...self::STATES,'REFUNDED'];
    public static function cents(mixed $amount): int
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) throw new SyncFailure(422);
        $text=(string)$amount;
        if (!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D',$text,$m)) throw new SyncFailure(422);
        return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
    }
    public static function map(array $order): array
    {
        if (($order['currency_iso_code']??'')!=='TRY' || !is_string($order['order_id']??null) || strlen($order['order_id'])>150 || !in_array($order['order_state']??'',self::STATES,true)) throw new SyncFailure(422);
        $date=$order['created_date']??'';
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T/',$date)) throw new SyncFailure(422);
        try { $month=(new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m'); }
        catch (\Throwable) { throw new SyncFailure(422); }
        if (!is_array($order['order_lines']??null) || !$order['order_lines']) throw new SyncFailure(422);
        $gross=0; $cancel=0; $refund=0; $products=[];
        foreach($order['order_lines'] as $line) {
            $line=KoctasCancelledLine::restore($line);
            if (!in_array($line['order_line_state']??'',self::LINE_STATES,true)) throw new SyncFailure(422);
            // price is already the whole line's price, not the unit price. Shipping is excluded.
            $price=self::cents($line['price']??null); $lineCancel=0; $lineRefund=0;
            foreach($line['cancelations']??[] as $item) $lineCancel+=self::cents($item['amount']??null);
            foreach($line['refunds']??[] as $item) {
                if (!in_array($item['refund_state']??'',['WAITING_REFUND_TAX_CONFIRMATION','WAITING_REFUND','WAITING_REFUND_PAYMENT','REFUNDED'],true)) throw new SyncFailure(422);
                if ($item['refund_state']==='REFUNDED') $lineRefund+=self::cents($item['amount']??null);
            }
            if (in_array($line['order_line_state'],['CANCELED','REFUSED'],true)) $lineCancel=$price;
            if ($lineCancel+$lineRefund>$price) throw new SyncFailure(422);
            $gross+=$price; $cancel+=$lineCancel; $refund+=$lineRefund;
            $products[]=KoctasProductMapper::map($line,$price,$lineCancel,$lineRefund);
        }
        // Staging orders have not yet been placed successfully and are excluded from sales.
        if ($order['order_state']==='STAGING') $gross=$cancel=$refund=0;
        return ['externalId'=>$order['order_id'],'month'=>$month,'state'=>$order['order_state'],'createdAt'=>$date,'grossCents'=>$gross,'cancelCents'=>$cancel,'returnCents'=>$refund,'orders'=>$order['order_state']==='STAGING'?0:1,'products'=>$order['order_state']==='STAGING'?[]:$products];
    }
}
