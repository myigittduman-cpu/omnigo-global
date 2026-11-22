<?php
declare(strict_types=1);
namespace Omnigo;

final class UpcomingManualPayout
{
    public const SOURCE = 'Manuel hakediş tahmini';

    public static function channels(Database $db, int $company, array $active): array
    {
        $channels = array_values(array_intersect(array_column(UpcomingPayoutSource::PANEL_CHANNELS, 'channel'), $active));
        if (in_array('Trendyol', $active, true) && CustomerSetupSelection::hasManual($db, $company, 'payoutEstimates')) {
            $markets = array_column((new CustomerMarketplaces($db))->listing($company), null, 'provider');
            $trendyol = $markets['trendyol'];
            if ($trendyol['selected'] && $trendyol['configured']) $channels[] = $trendyol['name'];
        }
        return $channels;
    }

    /** Called under the company write lock used by all payment records. */
    public static function existing(Database $db, Repository $repo, array $actor, int $company, array $entry): ?array
    {
        $channels = CustomerMarketplaces::reportingChannels($db,$company,$repo->records($company,'channels',$actor));
        $active = array_column(array_filter($channels, static fn(array $row): bool =>
            $row['status'] === 'active' && MarketplaceVisibility::visible($row['name'])), 'name');
        if (!in_array($entry['channel'],self::channels($db,$company,$active),true)) {
            Http::fail(403,'Bu mağaza ve pazaryeri için manuel hakediş girişi kullanılamıyor.');
        }
        $today = (new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        if ($entry['status'] !== 'pending' || $entry['verification'] !== 'estimated'
            || $entry['expectedDate'] < $today || $entry['expectedCents'] <= 0) {
            Http::fail(400,'Bugün veya sonrası için sıfırdan büyük bir tahmini net tutar girin.');
        }
        $matches = array_values(array_filter($repo->records($company,'payments',$actor),static fn(array $record): bool =>
            $record['channel'] === $entry['channel'] && $record['expectedDate'] === $entry['expectedDate']));
        if (!$matches) return null;
        $record = $matches[0];
        if (count($matches) > 1 || $record['status'] !== 'pending'
            || ($record['verification'] ?? '') === 'confirmed' || !empty($record['paymentStateUnknown'])) {
            Http::fail(409,'Bu pazaryeri ve tarihte başka bir hakediş var. Takvim kayıtlarından kontrol edin.');
        }
        return $record;
    }
}
