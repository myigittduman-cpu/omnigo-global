<?php
declare(strict_types=1);
namespace Omnigo;

/** A Sale movement proves its own commission. Fees and tax invoices use a separate ledger. */
final class TrendyolSaleCommission
{
    /** Trendyol may report eight decimals. Round the order total only after summing its movements. */
    private static function units(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
        if (is_float($value) && is_finite($value) && $value >= 0 && $value < 1000000000) {
            $formatted=number_format($value,8,'.','');
            if ((float)$formatted!==$value) return null;
            $value=$formatted;
        }
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,8}))?$/D',(string)$value,$parts)) return null;
        return (int)$parts[1]*100000000+(int)str_pad($parts[2]??'',8,'0');
    }

    public static function movement(array $row, array $order): ?array
    {
        if (!in_array($row['transactionType']??null,['Satış','Sale'],true)
            || !in_array($row['currency']??null,['TRY','TL'],true)
            || ($row['affiliate']??'TRENDYOLTR')!=='TRENDYOLTR'
            || (string)($row['orderNumber']??'')!==(string)$order['externalId']) return null;
        // Order and finance APIs can use different timestamps for the same unit.
        // The worker checks seller; evidence requires the complete order's exact line IDs and prices.
        $transactionId=$row['id']??null;
        $lineId=$row['orderLineItemId']??null;
        if ((!is_int($transactionId) && !is_string($transactionId)) || (!is_int($lineId) && !is_string($lineId))) return null;
        $id=(string)$transactionId;
        $line=(string)$lineId;
        if (!preg_match('/^[1-9]\d{0,19}$/D',$id) || !preg_match('/^[1-9]\d{0,19}$/D',$line)) return null;
        $credit=self::units($row['credit']??null);
        $fee=self::units($row['commissionAmount']??null);
        $revenue=self::units($row['sellerRevenue']??null);
        if ($credit===null || $credit===0 || $fee===null || $revenue===null
            || self::units($row['debt']??null)!==0 || $fee+$revenue!==$credit
            || $credit%1000000!==0) return null;
        $rate=self::units($row['commissionRate']??null);
        $points=$rate!==null && $rate<=10000000000 && $rate%1000000===0 ? intdiv($rate,1000000) : null;
        return ['id'=>$id,'line'=>$line,'creditCents'=>intdiv($credit,1000000),'feeUnits'=>$fee,'rate'=>$points];
    }

    public static function evidence(array $order, array $movements): ?array
    {
        $lines=$order['products']??[];
        if (!$lines || count($movements)!==count($lines)) return null;
        $byLine=array_column($movements,null,'line');
        if (count($byLine)!==count($movements) || count(array_unique(array_column($movements,'id')))!==count($movements)) return null;
        $total=0;
        $rates=[];
        foreach ($lines as $line) {
            $id=$line['lineId']??'';
            $entry=$byLine[$id]??null;
            if ($entry===null || ($line['quantity']??null)!==1
                || !in_array($entry['creditCents'],[$line['grossCents']??null,$line['pricing']['originalCents']??null],true)
                || $entry['feeUnits']>PHP_INT_MAX-500000-$total) return null;
            unset($byLine[$id]);
            $total+=$entry['feeUnits'];
            $rates[]=$entry['rate'];
        }
        if ($byLine) return null;
        $commissionCents=intdiv($total+500000,1000000);
        $gross=$order['grossCents']??null;
        $facts=[
            'commissionCents'=>$commissionCents,
            'commissionRateBasisPoints'=>count(array_unique($rates,SORT_REGULAR))===1 && !in_array(null,$rates,true)?$rates[0]:null,
            'commissionSource'=>'marketplace_sale',
        ];
        // sellerRevenue per Trendyol settlement = credit - commission (order-level payout before monthly service fees).
        if(is_int($gross)&&$gross>0){
            $payout=$gross-$commissionCents;
            if($payout>=0){$facts['afterDeductionsCents']=$payout;$facts['financialStatus']='reconciled';$facts['financialSource']='marketplace_sale';}
        }
        return ProfitabilityFinancialEvidence::seal($order,$facts);
    }
}
