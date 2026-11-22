<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only presentation of already imported records in a requested date range. */
final class DashboardDateRange
{
    public static function sales(array $summary,string $month): array
    {
        $rows=[];
        foreach($summary['channels'] as $item){
            $period=$item['periods']['month']??null;
            if(empty($period['available']))continue;
            $rows[]=['channel'=>$item['channel'],'month'=>$month,...array_intersect_key($period,SalesCalendar::ZERO),
                'integration'=>MarketplaceSalesSummary::PROVIDERS[$item['channel']]??'',
                'refundsVerified'=>$item['refundsVerified']??true,'source'=>'Seçili tarih aralığındaki sipariş kayıtları','updated'=>$item['asOf']];
        }
        return $rows;
    }
    public static function includes(string $date,string $from,string $to): bool
    {
        return $date!==''&&$date>=$from&&$date<=$to;
    }
}
