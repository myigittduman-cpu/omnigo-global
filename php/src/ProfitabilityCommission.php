<?php
declare(strict_types=1);
namespace Omnigo;

final class ProfitabilityCommission
{
    public static function day(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
    }

    public static function amount(int $sales,int $basisPoints): int
    {
        return intdiv($sales,10000)*$basisPoints+intdiv(($sales%10000)*$basisPoints+5000,10000);
    }

    public static function share(int $amount,int $sales): ?int
    {
        if ($sales<=0) return null;
        $points=round($amount/$sales*10000);
        return $points<=9007199254740991?(int)$points:null;
    }

    public static function fingerprint(array $lines): string
    {
        $shape=[];
        foreach ($lines as $line) $shape[]=json_encode([ProfitabilityCommissionRates::sku((string)($line['sku']??'')),
            $line['quantity'],$line['netQuantity'],$line['grossCents'],$line['cancelCents'],$line['returnCents']],JSON_THROW_ON_ERROR);
        sort($shape,SORT_STRING);
        return hash('sha256',implode("\n",$shape));
    }

    public static function resolve(string $channel,int $timestamp,int $sales,array $lines,array $rates,?array $observed,?array $allLines=null): array
    {
        $pricing=[];
        if ($observed && $observed['orderedAt']===$timestamp && $observed['salesCents']===$sales
            && $observed['fingerprint']===self::fingerprint($lines)
            && (!array_key_exists('pricingFingerprint',$observed)
                ||$observed['pricingFingerprint']===ProfitabilityOrderPricing::fingerprint($allLines??$lines))) {
            $evidence=array_intersect_key($observed,array_flip(['commissionCents','commissionRateBasisPoints','commissionSource',
                'commissionNetCents','commissionTaxCents','serviceCents','paymentServiceCents','withholdingCents',
                'otherDeductionsCents','afterDeductionsCents','financialSource','financialStatus','statementDate','statementItems','checkedAt',
                'statementCouponCents','statementShippingCents','reportedPayoutCents',
                'orderPricing','orderLinePricing','pricingFingerprint','storePriceBasis','commissionDiscountCents']));
            if (isset($evidence['commissionSource']) || isset($evidence['financialStatus'])) return $evidence;
            $pricing=$evidence;
        }

        $day=self::day($timestamp);
        $applicable=[];
        foreach ($rates as $rate) {
            if ($rate['channel']!==$channel||$rate['effectiveFrom']>$day) continue;
            $sku=$rate['sku'];
            if (!isset($applicable[$sku])||$applicable[$sku]['effectiveFrom']<$rate['effectiveFrom']) $applicable[$sku]=$rate;
        }
        $bases=[];
        foreach ($lines as $line) {
            $sku=ProfitabilityCommissionRates::sku((string)($line['ruleSku']??$line['sku']??''));
            $rate=$applicable[$sku]??$applicable['']??null;
            if($rate===null)return [...$pricing,'commissionCents'=>null,'commissionRateBasisPoints'=>null,'commissionSource'=>'pending'];
            $points=$rate['basisPoints'];
            $bases[$points]=($bases[$points]??0)+$line['grossCents']-$line['cancelCents']-$line['returnCents'];
        }
        $commissionCents=0;
        foreach ($bases as $points=>$base) $commissionCents+=self::amount($base,$points);
        $commissionRateBasisPoints=count($bases)===1?array_key_first($bases):self::share($commissionCents,$sales);
        $commissionSource='manual';
        return [...$pricing,...compact('commissionCents','commissionRateBasisPoints','commissionSource')];
    }
}
