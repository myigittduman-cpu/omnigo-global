<?php
declare(strict_types=1);
namespace Omnigo;

/** Fixed turnover periods from the last completed Koçtaş source snapshot. */
final class KoctasCompletedPeriodMetrics
{
    public function __construct(private Database $db) {}

    public function read(int $company,array $windows,\DateTimeImmutable $from,\DateTimeImmutable $through,\DateTimeImmutable $clock): array
    {
        // Daily aggregates cannot be split at an earlier time within the same day.
        if($through<$from||$through>$clock)return [];
        $firstMonth=max($from,min(array_column($windows,0)))->format('Y-m');
        $lastMonth=$through->format('Y-m');
        $months=[];
        foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row){
            $sale=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if(($sale['integration']??null)!=='koctas'||($sale['channel']??null)!=='Koçtaş'||!is_string($sale['month']??null))continue;
            $month=$sale['month'];
            if($month<$firstMonth||$month>$lastMonth)continue;
            $months[$month]=array_key_exists($month,$months)?null:self::verifiedTotals($sale,$from,$through);
        }
        $periods=[];
        foreach($windows as $key=>[$start,$end]){
            $coveredFrom=max($start,$from);
            $coveredThrough=min($end,$through);
            if($coveredThrough<$coveredFrom)continue;
            $totals=SalesCalendar::ZERO;
            foreach(SalesCalendar::months($coveredFrom->format(DATE_ATOM),$coveredThrough->format(DATE_ATOM)) as $month){
                if(!isset($months[$month]))continue 2;
                SalesCalendar::add($totals,$months[$month]);
            }
            $periods[$key]=[
                'turnoverCents'=>$totals['grossCents'],
                'returnCents'=>$totals['returnCents'],
                'orders'=>$totals['orders'],
                'partial'=>$from>$start||$through->format('Y-m-d')<$end->format('Y-m-d'),
                'availableFrom'=>$coveredFrom->format('Y-m-d'),
            ];
        }
        return $periods;
    }

    private static function verifiedTotals(array $sale,\DateTimeImmutable $from,\DateTimeImmutable $through): ?array
    {
        $daily=$sale['dailySales']??null;
        if(!is_array($daily)||!is_array($daily['days']??null)
            ||!self::matchesTimestamp($daily['from']??null,$from)
            ||!self::matchesTimestamp($daily['through']??null,$through))return null;

        $firstDay=$from->format('Y-m-d');$lastDay=$through->format('Y-m-d');
        $totals=SalesCalendar::ZERO;
        foreach($daily['days'] as $day=>$values){
            if(!is_string($day)||!str_starts_with($day,$sale['month'].'-')||$day<$firstDay||$day>$lastDay||!is_array($values))return null;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$from->getTimezone());
            if(!$date||$date->format('Y-m-d')!==$day)return null;
            foreach(SalesCalendar::ZERO as $field=>$_)if(!is_int($values[$field]??null)||$values[$field]<0)return null;
            // A legacy mid-day start cannot certify which orders belong to that first date.
            if($day===$firstDay&&$from>$date&&$values['orders']!==0)return null;
            if($values['cancelCents']>$values['grossCents']||$values['returnCents']>$values['grossCents']-$values['cancelCents'])return null;
            if($values['orders']===0&&$values['grossCents']!==0)return null;
            SalesCalendar::add($totals,$values);
        }
        foreach($totals as $field=>$total)if(($sale[$field]??null)!==$total)return null;
        return $totals;
    }

    private static function matchesTimestamp(mixed $value,\DateTimeImmutable $expected): bool
    {
        if(!is_string($value)||$value==='')return false;
        try{return new \DateTimeImmutable($value,$expected->getTimezone())==$expected;}
        catch(\Exception){return false;}
    }
}
