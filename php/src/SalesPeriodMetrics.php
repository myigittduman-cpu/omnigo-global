<?php
declare(strict_types=1);
namespace Omnigo;

/** Istanbul calendar periods calculated from the same sales snapshot as the workspace. */
final class SalesPeriodMetrics
{
    public static function windows(\DateTimeImmutable $clock): array
    {
        $month=$clock->modify('first day of this month')->setTime(0,0);
        return [
            'month'=>[$month,$clock],
            'previousMonth'=>[$month->modify('-1 month'),$month->modify('-1 second')],
            'year'=>[$month->setDate((int)$month->format('Y'),1,1),$clock],
            'threeMonths'=>[$month->modify('-2 months'),$clock],
        ];
    }

    public static function marketplace(array $orders,array $windows,\DateTimeImmutable $from,\DateTimeImmutable $through,bool $refundsVerified,bool $customerView=false): array
    {
        $periods=[];
        foreach($windows as $key=>[$start,$end]){
            $value=SalesSummaryPeriods::window($orders,$start,$end,$from,$through);
            if(!$value['available'])continue;
            $periods[$key]=[
                'turnoverCents'=>$value[$customerView?'salesTurnoverCents':'turnoverCents'],
                'returnCents'=>$refundsVerified?$value['returnCents']:null,
                'orders'=>$value[$customerView?'salesOrders':'orders'],
                'partial'=>$value['partial'],
                'availableFrom'=>max($start,$from)->format('Y-m-d'),
            ];
        }
        return $periods;
    }

    public static function summarize(array $windows,array $channels): array
    {
        $groups=['all'=>$channels];
        foreach($channels as $channel=>$periods)$groups[$channel]=[$channel=>$periods];
        $summary=[];
        foreach($groups as $name=>$group){
            foreach($windows as $key=>[$from,$to]){
                $available=array_column($group,$key);
                $metrics=[];
                foreach(['turnoverCents','returnCents','orders'] as $field){
                    $values=array_column($available,$field);
                    $metrics[$field]=$values&&!in_array(null,$values,true)?array_sum($values):null;
                }
                $metrics['averageCents']=$metrics['orders']>0&&$metrics['turnoverCents']!==null
                    ?(int)round($metrics['turnoverCents']/$metrics['orders']):null;
                $availableFrom=$available?min(array_column($available,'availableFrom')):null;
                $summary[$name][$key]=[
                    'from'=>$availableFrom??$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),...$metrics,
                    'coverageFrom'=>$availableFrom!==null&&$availableFrom>$from->format('Y-m-d')?$availableFrom:null,
                    'partial'=>$available&&(count($available)<count($group)||in_array(true,array_column($available,'partial'),true)),
                ];
            }
        }
        return $summary;
    }
}
