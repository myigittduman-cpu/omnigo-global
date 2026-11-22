<?php
declare(strict_types=1);
namespace Omnigo;

/** Read projection only: fresh package/line states never rewrite financial snapshots. */
final class OrderDisplayStatus
{
    public static function label(string $provider,array $packages): ?string
    {
        if(!in_array($provider,['n11','Pazarama'],true)||!$packages)return null;
        $labels=[];
        foreach($packages as $package){
            $details=$package['details']??$package;
            if(!empty($details['superseded']))continue;
            $lines=$details['products']??[];
            foreach($lines?:[['status'=>$details['status']??'']] as $line){
                $status=(string)($line['status']??$details['status']??'');
                $label=self::sourceLabel($provider,$status);
                $labels[$label]=true;
            }
        }
        return $labels?implode(' / ',array_keys($labels)):null;
    }
    private static function sourceLabel(string $provider,string $status): string
    {
        if($provider==='Pazarama'&&in_array($status,['7','8','9','10'],true))return ReturnRequestMapper::status('pazarama',$status)[1];
        return match(OrderShipping::state($provider,$status)){
            'pending'=>'Hazırlanıyor','shipped'=>'Kargolandı','delivered'=>'Teslim edildi',
            'cancelled'=>'İptal edildi','undelivered'=>'Teslim edilemedi',
            default=>'Durum doğrulanamadı',
        };
    }
}
