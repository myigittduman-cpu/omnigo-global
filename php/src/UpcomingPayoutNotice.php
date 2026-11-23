<?php
declare(strict_types=1);
namespace Omnigo;

/** Publishes a revision reference, keeping financial amounts in the approval store. */
final class UpcomingPayoutNotice
{
    public const EVENT = 'upcoming_payout_amount_changed';
    private const PERIOD_DAYS = 15;

    public function __construct(private Database $db) {}

    public static function isEvent(array $detail): bool
    {
        return is_array($detail['events'] ?? null) && in_array(self::EVENT, $detail['events'], true);
    }

    public static function presentation(): array
    {
        return [
            'title'=>'Hakediş tutarı değişti',
            'body'=>'Yeni tutarı inceleyip tablodaki güncellemeyi onaylayabilirsiniz.',
            'view'=>'payments',
        ];
    }

    public function publish(int $company, array $change): void
    {
        if (!$this->db->pdo->inTransaction()) throw new \LogicException('Hakediş bildirimi değişiklikle birlikte kaydedilmeli.');
        $payload=[
            'audience'=>'admin', 'events'=>[self::EVENT], 'view'=>'payments',
            'payoutChannel'=>$change['channel'], 'payoutDate'=>$change['date'],
        ];
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        $dedup=$change['revision'];
        $added=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
            [$company,'portal',$dedup,'operation',json_encode($payload,JSON_THROW_ON_ERROR),gmdate('c')]);
        if ($added->rowCount()===1) PushRoutes::devices($this->db)->enqueue($this->db->insertId());
    }

    /** Match the table periods in upcoming-payment-periods.js and notify once when a proposal enters the current one. */
    public function publishCurrentPeriod(int $company,array $report,string $today): void
    {
        if (!$report['pendingChanges']) return;
        $items=(new UpcomingPayoutAdjustment($this->db))->apply($company,$report['items'],false);
        $dates=[];
        foreach ($items as $item) {
            if (MarketplaceVisibility::visible($item['channel'])) $dates[$item['date']][$item['channel']]=true;
        }
        ksort($dates);
        $start=$end=null;
        foreach (array_keys($dates) as $day) {
            if ($end !== null && $day <= $end) continue;
            if ($start !== null && $day > $today) break;
            $start=$day;
            $end=(new \DateTimeImmutable($day))->modify('+'.(self::PERIOD_DAYS-1).' days')->format('Y-m-d');
        }
        if ($start === null) return;
        foreach ($report['pendingChanges'] as $change) {
            $day=$change['date'];
            if ($day >= $start && $day <= $end && isset($dates[$day][$change['channel']])) {
                $this->publish($company,$change);
            }
        }
    }

    public function detail(int $company, int $id, array $summary): array
    {
        $event=$this->db->one("SELECT order_key FROM order_notifications WHERE id=? AND company_id=? AND event_type='operation'",[$id,$company]);
        $change=$event ? (new UpcomingPayoutReviewStore($this->db))->pendingRevision($company,$event['order_key']) : null;
        return [...$summary, 'payoutChange'=>$change];
    }
}
