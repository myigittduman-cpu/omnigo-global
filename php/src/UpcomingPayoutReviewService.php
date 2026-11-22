<?php
declare(strict_types=1);
namespace Omnigo;

/** Takes an independent snapshot of the forecasting inputs. No marketplace writes. */
final class UpcomingPayoutReviewService
{
    private UpcomingPayoutReviewStore $store;

    public function __construct(private Database $db)
    {
        $this->store = new UpcomingPayoutReviewStore($db);
    }

    public function observe(int $company): array
    {
        return $this->store->observe($company, fn(): array => $this->snapshot($company),
            $this->notice(...));
    }

    public function approve(array $actor,int $company,string $key,string $revision): array
    {
        return $this->store->approve($actor,$company,$key,$revision,
            fn(): array => $this->snapshot($company), $this->notice(...));
    }

    public function snapshot(int $company): array
    {
        $actor = ['role'=>'admin'];
        $repo = new Repository($this->db);
        $channels = CustomerMarketplaces::reportingChannels($this->db,$company,$repo->records($company,'channels',$actor));
        $scope = array_values(array_unique(array_column(MarketplaceVisibility::rows($channels,'name'),'name')));
        $active = array_column(array_filter($channels,static fn(array $row): bool => $row['status']==='active'),'name');
        $today = (new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $connected = array_intersect($scope,$active);
        $official = (new UpcomingPayoutStore($this->db))->rows($company,$connected,$today);
        $panel = (new UpcomingPanelPayoutStore($this->db))->snapshot($company,$connected,$today,true);
        return UpcomingPayments::snapshot($repo->records($company,'payments',$actor),$scope,$today,
            [...$official,...$panel['rows']],$panel['coverage']);
    }

    public static function run(Database $db): void
    {
        if (!UpcomingPayoutReviewStore::available($db)) return;
        $company = (new UpcomingPayoutStore($db))->pilot();
        if ($company !== null) (new self($db))->observe($company);
    }

    private function notice(int $company,array $change): void
    {
        (new UpcomingPayoutNotice($this->db))->publish($company,$change);
    }
}
