<?php
declare(strict_types=1);
namespace Omnigo;

final class UpcomingManualPayout
{
    public const SOURCE = 'Manuel hakediş tahmini';

    public static function channels(Database $db, int $company, array $active): array
    {
        // Manual forecasts are useful even for connected markets: a seller may
        // enter a correction or a payout that the marketplace API does not expose.
        // Keep this list aligned with the reporting channels, not connection state.
        return array_values(array_filter(Repository::CHANNELS, static fn(string $name): bool => MarketplaceVisibility::visible($name)));
    }

    /** Called under the company write lock used by all payment records. */
    public static function existing(Database $db, Repository $repo, array $actor, int $company, array $entry): ?array
    {
        if (!in_array($entry['channel'],self::channels($db,$company,[]),true)) {
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
