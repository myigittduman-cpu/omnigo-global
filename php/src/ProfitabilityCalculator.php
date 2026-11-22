<?php
declare(strict_types=1);
namespace Omnigo;

/** No assumed charges. Receivables and frozen purchase costs are separate facts. */
final class ProfitabilityCalculator
{
    private const MAX_CENTS=9007199254740991;

    public static function row(array $snapshot,?array $order,array $frozen,array $rates=[],?array $observed=null): array
    {
        $base=[
            'orderNumber'=>$snapshot['order_number'],'channel'=>OrderList::PROVIDERS[$snapshot['provider']],
            'orderedAt'=>gmdate('c',(int)$snapshot['ordered_at']),
            'salesCents'=>null,'commissionCents'=>null,'shippingCents'=>null,'purchaseCents'=>null,'profitCents'=>null,
            'serviceCents'=>null,'paymentServiceCents'=>null,'withholdingCents'=>null,'commissionNetCents'=>null,'commissionTaxCents'=>null,
            'otherDeductionsCents'=>null,'afterDeductionsCents'=>null,
            'statementCouponCents'=>null,'statementShippingCents'=>null,
            'orderPricing'=>ProfitabilityOrderPricing::UNKNOWN,'commissionDiscountCents'=>null,'storePriceBasis'=>null,
            'products'=>array_map(fn($line)=>self::product($line,(int)$line['quantity']),$frozen),
        ];
        if(!$order)return [...$base,'status'=>'sales_pending'];
        $canonicalTime=strtotime($order['createdAt']??'');
        $capturedTime=(int)$snapshot['ordered_at'];
        if($canonicalTime!==$capturedTime&&($snapshot['provider']!=='n11'||$canonicalTime===false||$canonicalTime<$capturedTime))
            return [...$base,'status'=>'sales_pending'];
        $sales=self::net($order);
        $state=strtoupper((string)($order['state']??''));
        $cancelled=in_array($state,['CANCELED','CANCELLED','REFUSED'],true);
        $returned=in_array($state,['RETURNED','REFUNDED'],true);
        if($sales===0&&(($order['cancelCents']??0)>0||($order['returnCents']??0)>0)){
            $returned=!empty($order['returnCents']);
            $cancelled=!$returned;
        }
        if($cancelled||$returned)return [...$base,'status'=>$returned?'returned':'cancelled','salesCents'=>0];
        if($sales===null||empty($order['orders']))return [...$base,'status'=>'sales_pending'];

        $financial=$order['products']??[];
        $details=self::verifiedLines($financial,$sales);
        if($details===null)return [...$base,'status'=>'sales_pending'];
        if(!$details)return [...$base,'status'=>!empty($order['returnCents'])?'returned':'cancelled','salesCents'=>0];
        $byLine=array_column($frozen,null,'line_key');
        $bySignature=[];
        foreach($frozen as $line)$bySignature[$line['match_key']][]=$line;
        $matching=array_count_values(array_map(ProfitabilityOrderCapture::matchKey(...),$financial));

        $cost=0;$complete=true;$products=[];$commissionLines=[];
        foreach($details as $line){
            $key=ProfitabilityOrderCapture::lineKey($line);
            $signature=ProfitabilityOrderCapture::matchKey($line);
            $matches=$bySignature[$signature]??[];
            $saved=$key!==null?($byLine[$key]??null):(($matching[$signature]??0)===1&&count($matches)===1?$matches[0]:null);
            if($saved&&((int)$saved['quantity']!==$line['quantity']||$saved['match_key']!==$signature))$saved=null;
            $quantity=$line['netQuantity'];
            $product=self::product($saved??['sku'=>$line['sku']??'','title'=>$line['title']??'','unit_purchase_cents'=>null],$quantity);
            $product['orderPricing']=ProfitabilityOrderPricing::fromOrder($snapshot['provider'],[$line]);
            if($snapshot['provider']==='koctas')$product['salesCents']=self::net($line);
            $unit=$product['unitPurchaseCents'];
            if($unit===null||$unit>intdiv(self::MAX_CENTS-$cost,$quantity))$complete=false;
            else $cost+=$unit*$quantity;
            $products[]=$product;
            $commissionLines[]=[...$line,'ruleSku'=>$product['sku']?:($line['sku']??'')];
        }
        $commission=ProfitabilityCommission::resolve($base['channel'],$capturedTime,$sales,$commissionLines,$rates,$observed,$financial);
        $orderPricing=ProfitabilityOrderPricing::fromOrder($snapshot['provider'],$details);
        if(isset($commission['orderPricing'],$commission['pricingFingerprint'])
            && $commission['pricingFingerprint']===ProfitabilityOrderPricing::fingerprint($financial)){
            $orderPricing=ProfitabilityOrderPricing::merge($orderPricing,$commission['orderPricing']);
            if($orderPricing['storePriceCents']===null)unset($commission['storePriceBasis']);
            foreach($details as $index=>$line){
                $quote=$commission['orderLinePricing'][ProfitabilityOrderPricing::fingerprint([$line])]??null;
                if(is_array($quote)){
                    $products[$index]['orderPricing']=ProfitabilityOrderPricing::merge($products[$index]['orderPricing'],$quote);
                    if($products[$index]['orderPricing']['storePriceCents']!==null&&($quote['storePriceBasis']??null)==='buyer_plus_discounts')
                        $products[$index]['storePriceBasis']='buyer_plus_discounts';
                }
            }
            if(count($products)===1){
                $products[0]['orderPricing']=$orderPricing;
                $products[0]['storePriceBasis']=$commission['storePriceBasis']??null;
            }
        }
        unset($commission['pricingFingerprint'],$commission['orderPricing'],$commission['orderLinePricing']);
        $base=[...$base,...$commission,'salesCents'=>$sales,'orderPricing'=>$orderPricing];
        $checked=strtotime($base['checkedAt']??'');
        $hasNet=is_int($base['afterDeductionsCents'])&&($base['financialStatus']??null)==='reconciled';
        $fresh=$checked!==false&&$checked<=time()+60&&$checked>=time()-86400;
        // A statement receivable is not profit. Unknown acquisition costs do not
        // hide a reconciled receivable; neither are they silently treated as zero.
        if(!$hasNet)$base['afterDeductionsCents']=null;
        return [...$base,'products'=>$products,'purchaseCents'=>$complete?$cost:null,
            'purchaseStatus'=>$complete?'known':'pending',
            'status'=>$hasNet?($fresh?'reconciled':'statement_stale'):'financials_pending'];
    }

    private static function product(array $line,int $quantity): array
    {
        $amount=$line['unit_purchase_cents'];
        return ['sku'=>$line['sku'],'title'=>$line['title'],'quantity'=>$quantity,'unitPurchaseCents'=>$amount===null?null:(int)$amount,
            'orderPricing'=>ProfitabilityOrderPricing::UNKNOWN,'storePriceBasis'=>null];
    }

    /** @return array|null null when sales/quantity do not reconcile; [] when fully reversed. */
    private static function verifiedLines(array $lines,int $sales): ?array
    {
        if(!$lines)return null;
        $active=[];$net=0;$ids=[];
        foreach($lines as $line){
            if(!is_array($line))return null;
            $quantity=$line['quantity']??null;
            $remaining=$line['netQuantity']??null;
            $amount=self::net($line);
            if(!is_int($quantity)||$quantity<1||!is_int($remaining)||$remaining<0||$remaining>$quantity||$amount===null
                ||($remaining===0&&$amount!==0)||$amount>self::MAX_CENTS-$net)return null;
            $key=ProfitabilityOrderCapture::lineKey($line);
            if($key!==null){
                if(isset($ids[$key]))return null;
                $ids[$key]=true;
            }
            if($remaining>0)$active[]=$line;
            $net+=$amount;
        }
        return $net===$sales?$active:null;
    }

    private static function net(array $source): ?int
    {
        $gross=$source['grossCents']??null;
        $cancel=$source['cancelCents']??null;
        $return=$source['returnCents']??null;
        if(!is_int($gross)||!is_int($cancel)||!is_int($return)||$gross<0||$gross>self::MAX_CENTS
            ||$cancel<0||$cancel>$gross||$return<0||$return>$gross-$cancel)return null;
        return $gross-$cancel-$return;
    }
}
