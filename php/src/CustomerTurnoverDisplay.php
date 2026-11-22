<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only customer presentation. Source invoice and accounting amounts stay intact. */
final class CustomerTurnoverDisplay
{
    public const BASIS='after_cancellations';
    public static function canView(array $access): bool
    {
        return ($access['storeRole'] ?? null) !== CustomerAccountRoles::LOGISTICS;
    }

    public static function project(array $value,bool $afterCancellation=false):array
    {
        foreach($value as $key=>$item){
            if(is_array($item)&&!in_array($key,['payments','invoice'],true))$value[$key]=self::project($item,$afterCancellation);
        }
        if(array_key_exists('salesOrders',$value))$value['orders']=$value['salesOrders'];
        $hasUnits=array_key_exists('salesQuantity',$value)||array_key_exists('orderedQuantity',$value);
        if($hasUnits){
            $units=array_key_exists('salesQuantity',$value)?$value['salesQuantity']
                :(($value['turnoverDisplayBasis']??null)===self::BASIS?($value['quantity']??null)
                    :SalesUnitTotals::activeLine(['quantity'=>$value['orderedQuantity']]+$value));
            $value['quantity']=$units;
            foreach(['orderedQuantity','netQuantity'] as $key){
                if(array_key_exists($key,$value))$value[$key]=$units;
            }
        }
        $hasTurnover=array_key_exists('turnoverCents',$value)||array_key_exists('grossCents',$value);
        if($hasTurnover){
            $amount=self::salesAmount($value);
            $value['turnoverCents']=$amount;
            foreach(['grossCents','salesCents','amountCents','panelSalesCents'] as $field){
                if(array_key_exists($field,$value))$value[$field]=$amount;
            }
            if(array_key_exists('turnoverAverageCents',$value)){
                $count=$value['orders']??null;
                $value['turnoverAverageCents']=$amount!==null&&is_int($count)&&$count>0?(int)round($amount/$count):null;
            }
            if(!empty($value['integration'])&&isset($value['source']))$value['source']='Pazaryeri aktarımı';
        }
        unset($value['salesTurnoverCents'],$value['salesTurnoverAverageCents'],$value['salesOrders'],
            $value['netCents'],$value['averageCents'],$value['unknownQuantities'],$value['refundCoverage'],$value['returnedOrders']);
        if($hasTurnover||$hasUnits||isset($value['periods']))$value['turnoverDisplayBasis']=self::BASIS;
        if(isset($value['salesBasis']))$value['salesBasis']='marketplace_sales_excluding_cancellations';
        return $value;
    }

    private static function salesAmount(array $row):?int
    {
        if(($row['turnoverDisplayBasis']??null)===self::BASIS||array_key_exists('salesTurnoverCents',$row)){
            $amount=($row['turnoverDisplayBasis']??null)===self::BASIS?($row['turnoverCents']??null):$row['salesTurnoverCents'];
            return is_int($amount)&&$amount>=0?$amount:null;
        }
        $gross=array_key_exists('turnoverCents',$row)?$row['turnoverCents']:$row['grossCents'];
        $cancelled=array_key_exists('cancelledTurnoverCents',$row)?$row['cancelledTurnoverCents']:($row['cancelCents']??null);
        return is_int($gross)&&is_int($cancelled)&&$cancelled>=0&&$cancelled<=$gross?$gross-$cancelled:null;
    }

    /** Sales-only projection. Order and invoice responses do not use it. */
    public static function withoutAmounts(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && (str_ends_with($key, 'Cents') || $key === 'turnoverDisplayBasis')) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = self::withoutAmounts($item);
            }
        }
        return $value;
    }

    public static function dashboardWithoutAmounts(array $value): array
    {
        $payments = array_intersect_key($value['summary'], array_flip(['expectedCents','paidCents','sentCents','undatedCents']));
        foreach (['summary','sales','salesToday','marketplaceSales'] as $section) {
            $value[$section] = self::withoutAmounts($value[$section]);
        }
        $value['summary'] += $payments;
        $value['sensitiveVisibility']['turnover'] = false;
        unset($value['turnoverDisplayBasis']);
        return $value;
    }
}
