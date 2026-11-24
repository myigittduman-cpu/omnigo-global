<?php
declare(strict_types=1);
namespace Omnigo;

/** Only plans the next stock action. Existing marketplace requests are left untouched. */
final class InventoryCentralStockTransferPreview
{
    public function __construct(private Database $db) {}

    public function plan(int $company, string $card, array $channels): array
    {
        $attempts = [];
        if (IntegrationImportSchema::tableAvailable($this->db, 'marketplace_stock_requests', 'company_id')) {
            $key = InventoryStockCard::productKeySql($this->db, 'r');
            $rows = $this->db->all("SELECT r.request_id,r.quantity,r.state,r.updated FROM marketplace_stock_requests r
                WHERE r.company_id=? AND $key=?
                ORDER BY CASE WHEN r.state IN ('succeeded','failed') THEN 1 ELSE 0 END,r.updated DESC,r.request_id DESC",
                [$company, $card]);
            foreach ($rows as $row) {
                $origin = MarketplaceStockJob::origin($row['request_id']) ?? 'hepsiburada';
                $provider = MarketplaceStockJob::CHANNELS[$origin];
                $attempts[$provider] ??= $row;
            }
        }

        $plan = [];
        foreach ($channels as $provider => $channel) {
            $plan[$provider] = self::next($channel, $attempts[$provider] ?? null);
        }
        return $plan;
    }

    public static function next(array $channel, ?array $previous): array
    {
        $quantity = $channel['expected'];
        $state = $previous['state'] ?? null;
        $previousQuantity = $previous === null ? null : (int)$previous['quantity'];
        $action = match (true) {
            in_array($state, ['sending','accepted','uncertain'], true) => 'verify_previous',
            $state === 'queued' && $previousQuantity !== $quantity => 'hold_stale_queued',
            $state === 'queued' => 'wait_queued',
            $state !== null && !in_array($state, ['failed','succeeded'], true) => 'hold_unknown_state',
            $channel['decision'] === 'hold' => 'wait_for_verified_data',
            $state === 'succeeded' && strtotime($channel['observedAt'] ?? '') < strtotime($previous['updated']) => 'verify_catalog_after_transfer',
            $channel['decision'] === 'already_correct' => 'no_change',
            $state === 'failed' => 'would_retry_latest',
            default => 'would_send_latest',
        };
        return ['action'=>$action, 'latestQuantity'=>$quantity, 'previousState'=>$state, 'previousQuantity'=>$previousQuantity];
    }
}
