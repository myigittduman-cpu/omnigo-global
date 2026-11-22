<?php
declare(strict_types=1);
namespace Omnigo;

/** Order-bound evidence only. Missing fields never become zero or a final receivable. */
final class ProfitabilityFinancialEvidence
{
    public static function cents(mixed $value): ?int
    {
        if ((!is_int($value)&&!is_float($value)&&!is_string($value))
            ||!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,8}))?$/D',(string)$value,$m)) return null;
        $fraction=str_pad($m[3]??'',3,'0');
        $amount=(int)$m[2]*100+(int)substr($fraction,0,2)+((int)$fraction[2]>=5?1:0);
        return ($m[1]??'')==='-'?-$amount:$amount;
    }

    public static function seal(array $order,array $facts): ?array
    {
        $lines=$order['products']??[];$sales=$order['grossCents']??null;
        if (empty($order['orders'])||!$lines||!is_int($sales)||$sales<=0
            ||!empty($order['cancelCents'])||!empty($order['returnCents'])) return null;
        foreach($lines as $line)if(($line['netQuantity']??null)!==($line['quantity']??null)
            ||!empty($line['cancelCents'])||!empty($line['returnCents']))return null;
        $orderedAt=strtotime($order['createdAt']??'');
        if(!$orderedAt)return null;
        return [...$facts,'orderedAt'=>$orderedAt,'salesCents'=>$sales,
            'fingerprint'=>ProfitabilityCommission::fingerprint($lines),'pricingFingerprint'=>ProfitabilityOrderPricing::fingerprint($lines),
            'checkedAt'=>gmdate('c')];
    }

    public static function hepsiburada(array $order,array $rawLines): ?array
    {
        $net=0;$rates=[];$pricing=[];$linePricing=[];$lines=[];
        foreach($order['products']??[] as $product){
            $id=(string)($product['lineId']??'');
            if($id===''||isset($lines[$id]))return null;
            $lines[$id]=$product;
        }
        $completeCommission=true;$derivedStore=false;
        foreach($rawLines as $line){
            $id=(string)($line['id']??'');
            if(!isset($lines[$id]))return null;
            $product=$lines[$id];unset($lines[$id]);
            $quantity=$line['quantity']??null;
            $unit=self::cents($line['commission']['amount']??null);
            $rate=self::cents($line['commissionRate']??null);
            $price=self::cents($line['totalPrice']['amount']??null);
            if(!is_int($quantity)||$quantity<1||$quantity>100000||$quantity!==$product['quantity']
                ||$price!==$product['grossCents']||($line['totalPrice']['currency']??null)!=='TRY')return null;
            $part=ProfitabilityMarketplacePricing::hepsiburada($line);
            $pricing[]=$part;
            ProfitabilityOrderPricing::recordLine($linePricing,$product,$part);
            $derivedStore=$derivedStore||isset($part['storePriceBasis']);
            if($unit===null||$unit<0||$unit>intdiv(9007199254740991-$net,$quantity)
                ||$rate===null||$rate<0||$rate>10000||($line['commission']['currency']??null)!=='TRY'){
                $completeCommission=false;continue;
            }
            $lineNet=$unit*$quantity;
            $difference=self::cents($line['deptorDifferenceAmount']??null);
            // A matching commission base establishes unit vs line arithmetic. It does
            // not establish the list price or which party funded a discount.
            $bases=[$price,$part['storePriceCents']];
            if($difference!==null&&$difference>=0&&$difference<=intdiv(9007199254740991-$price,$quantity))$bases[]=$price+$difference*$quantity;
            if($quantity>1&&!array_filter($bases,static fn($base)=>$base!==null
                &&abs(ProfitabilityCommission::amount($base,$rate)-$lineNet)<=$quantity)){
                $completeCommission=false;continue;
            }
            $net+=$lineNet;$rates[]=$rate;
        }
        if($lines||!$pricing)return null;
        $rates=array_values(array_unique($rates));
        $facts=$completeCommission?['commissionCents'=>null,'commissionNetCents'=>$net,
            'commissionTaxCents'=>null,'commissionRateBasisPoints'=>count($rates)===1?$rates[0]:null,
            'commissionSource'=>'marketplace_net']:[];
        $amounts=ProfitabilityOrderPricing::sum($pricing);
        if(!$facts&&$amounts===ProfitabilityOrderPricing::UNKNOWN&&!ProfitabilityOrderPricing::hasLineEvidence($linePricing))return null;
        return self::seal($order,[...$facts,'orderPricing'=>$amounts,'orderLinePricing'=>$linePricing,'storePriceBasis'=>$derivedStore&&$amounts['storePriceCents']!==null?'buyer_plus_discounts':null,
            'pricingFingerprint'=>ProfitabilityOrderPricing::fingerprint($order['products'])]);
    }

    public static function pazarama(array $order,array $movements,string $orderNumber): ?array
    {
        $selected=[];
        foreach($movements as $raw){
            if((string)($raw['orderId']??'')!==$orderNumber)continue;
            // Reversals or unallocated adjustments cannot be represented as a complete sale.
            if(($raw['status']??null)!=='Satış')return null;
            $identity=$raw['trxId']??null;
            if((!is_string($identity)&&!is_int($identity))||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',(string)$identity))return null;
            $selected[]=$raw;
        }
        // Split instalments require a documented allocation contract, not guessed sums.
        if(count($selected)!==1)return null;
        $raw=$selected[0];$values=[];
        foreach(['currency','currencyCode'] as $field)
            if(isset($raw[$field])&&!in_array($raw[$field],['TL','TRY',949,'949'],true))return null;
        foreach(['amount','commissionAmount','integrationAmount','stoppageAmount','merchantCargoDebt','couponDiscount','allowanceAmount'] as $key){
            $values[$key]=self::cents($raw[$key]??null);
        }
        ['amount'=>$sale,'commissionAmount'=>$commission,'integrationAmount'=>$service,
            'stoppageAmount'=>$withholding,'merchantCargoDebt'=>$cargo,'couponDiscount'=>$coupon,'allowanceAmount'=>$net]=$values;
        if($sale===null||$sale!==($order['grossCents']??null)||$sale<=0)return null;
        try{$date=FinanceMapper::date($raw['transactionDate']??null);}catch(SyncFailure){return null;}
        $facts=[];
        if($commission!==null&&$commission<=0)$facts=['commissionCents'=>-$commission,
            'commissionRateBasisPoints'=>ProfitabilityCommission::share(-$commission,$sale),'commissionSource'=>'marketplace_effective'];
        if($service!==null&&$service<=0)$facts['serviceCents']=-$service;
        if($withholding!==null&&$withholding<=0)$facts['withholdingCents']=-$withholding;
        if($net!==null&&$net>=0)$facts['reportedPayoutCents']=$net;
        if(!$facts)return null;
        $facts['financialSource']='marketplace_statement';
        $facts['statementDate']=$date;
        // Include the signed marketplace movements once, only when the provider's actual payable agrees to the cent.
        // A coupon movement may debit or credit the merchant. Its funding is not described by this statement.
        if(!in_array(null,$values,true)&&$commission<=0&&$service<=0&&$withholding<=0&&$cargo<=0&&$net>=0
            &&$sale+$commission+$service+$withholding+$cargo+$coupon===$net){
            $netOther=-($service+$withholding+$cargo+$coupon);
            if($netOther>=0)$facts['otherDeductionsCents']=$netOther;
            $facts['afterDeductionsCents']=$net;
            $facts['financialStatus']='reconciled';
            $facts['statementCouponCents']=$coupon;
            $facts['statementShippingCents']=$cargo;
        }
        return self::seal($order,$facts);
    }
}
