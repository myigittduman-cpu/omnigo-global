<?php
declare(strict_types=1);
namespace Omnigo;

/** Compare approved bank receipts by their actual payment date. */
final class OverviewPaymentPeriods
{
    public static function summarize(array $payments,array $channels,\DateTimeImmutable $clock): array
    {
        $windows=array_intersect_key(SalesPeriodMetrics::windows($clock),array_flip(['month','previousMonth']));
        $totals=array_map(static fn(array $window):array=>[
            'from'=>$window[0]->format('Y-m-d'),'to'=>$window[1]->format('Y-m-d'),'paidCents'=>0,
        ],$windows);
        $allowed=array_fill_keys($channels,true);
        foreach($payments as $payment){
            if(!isset($allowed[$payment['channel']])||$payment['status']!=='paid'||!PaymentApproval::isApproved($payment)
                ||!empty($payment['excludedFromTotals'])||!empty($payment['paymentStateUnknown'])
                ||!is_int($payment['actualCents']??null))continue;
            $date=$payment['paidDate']??'';
            foreach($totals as &$period){
                if($date>=$period['from']&&$date<=$period['to'])$period['paidCents']+=$payment['actualCents'];
            }
            unset($period);
        }
        return $totals;
    }
}
