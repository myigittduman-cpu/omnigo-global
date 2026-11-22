<?php
declare(strict_types=1);
namespace Omnigo;

/** One published projection for the calendar and the overview. */
final class UpcomingPaymentForecast
{
    public function __construct(private Database $db) {}

    public function read(int $company,array $payments,array $channels,array $allowed,string $channel='all',
        bool $manage=false,bool $capture=false): array
    {
        $scope = array_values(array_unique($channel==='all'?$allowed:array_intersect($allowed,[$channel])));
        $active = array_column(array_filter($channels,static fn(array $row): bool => $row['status']==='active'),'name');
        $connected = array_intersect($scope,$active);
        $today = (new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $official = (new UpcomingPayoutStore($this->db))->rows($company,$connected,$today);
        $panel = (new UpcomingPanelPayoutStore($this->db))->snapshot($company,$connected,$today);
        $snapshot = UpcomingPayments::snapshot($payments,$scope,$today,[...$official,...$panel['rows']],$panel['coverage']);
        $review = null;
        if (UpcomingPayoutReviewStore::available($this->db)) {
            $review = $manage && $capture
                ? (new UpcomingPayoutReviewService($this->db))->observe($company)
                : (new UpcomingPayoutReviewStore($this->db))->report($company,$today);
        }
        $periodItems = [];
        foreach ($review['items']??$snapshot['items'] as $item) {
            if (!in_array($item['channel'],$allowed,true)) continue;
            unset($item['sourceConnection']);
            $periodItems[] = $item;
        }
        $forecast = UpcomingPayments::projection(['items'=>$periodItems,'paidThisMonthCents'=>$snapshot['paidThisMonthCents']],$scope,$today);
        $forecast['periodItems'] = $periodItems;
        $forecast['manualEntryChannels'] = UpcomingManualPayout::channels($this->db,$company,$connected);
        if ($manage) {
            $forecast['manualChannels'] = $forecast['manualEntryChannels'];
            $forecast['sourceWarnings'] = (new MarketplaceServiceHealth($this->db))->payoutWarnings($company,array_intersect($allowed,$active));
            if ($review !== null) $forecast['pendingChanges'] = array_values(array_filter($review['pendingChanges'],static fn(array $item): bool =>
                in_array($item['channel'],$scope,true)));
        }
        return $forecast;
    }
}
