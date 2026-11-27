<?php
declare(strict_types=1);
namespace Omnigo;

/** Published forecasts are separate from the financial imports and paid receipts. */
final class UpcomingPayoutReviewStore
{
    public function __construct(private Database $db) {}

    public static function install(Database $db): void
    {
        $suffix = $db->mysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $db->run('CREATE TABLE IF NOT EXISTS upcoming_payout_reviews (
            company_id INT NOT NULL, payout_key CHAR(64) NOT NULL, channel VARCHAR(40) NOT NULL,
            payment_date CHAR(10) NOT NULL, approved_payload MEDIUMTEXT NOT NULL, pending_payload MEDIUMTEXT,
            pending_revision CHAR(32), detected_at VARCHAR(35), applied_revision CHAR(32), settled INT NOT NULL DEFAULT 0,
            PRIMARY KEY(company_id,payout_key), UNIQUE(company_id,pending_revision),
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)'.$suffix);
    }

    public static function available(Database $db): bool
    {
        return (bool)($db->mysql() ? $db->one("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='upcoming_payout_reviews'")
            : $db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='upcoming_payout_reviews'"));
    }

    /** A callback reads marketplace data after the company write lock has been acquired. */
    public function observe(int $company, array|callable $snapshot, ?callable $notify = null,
        ?UpcomingPayoutNotice $periodNotices = null): array
    {
        return $this->withCompany($company, function () use ($company,$snapshot,$notify,$periodNotices): array {
            $snapshot = is_callable($snapshot) ? $snapshot() : $snapshot;
            $this->reconcile($company,$snapshot,$notify);
            $report = $this->report($company,$snapshot['today']);
            $periodNotices?->publishCurrentPeriod($company,$report,$snapshot['today']);
            return $report;
        });
    }

    public function report(int $company, ?string $today = null): array
    {
        $today ??= self::today();
        $items = $pendingChanges = [];
        $connections = [];
        foreach ($this->snapshots($company) as $record) {
            if ((int)$record['settled'] === 1 && $record['payment_date'] > $today) continue;
            foreach (self::rows($record['approved_payload']) as $item) {
                if ($item['amountCents'] <= 0 || ($item['date'] >= $today && !$this->matchesConnection($company,$item,$connections))) continue;
                unset($item['sourceConnection']);
                $items[]=$item;
            }
            if ($record['pending_revision'] !== null
                && $this->matchesConnection($company,self::rows($record['pending_payload'])[0],$connections)) {
                $pendingChanges[] = self::change($record);
            }
        }
        return compact('items','pendingChanges');
    }

    private function matchesConnection(int $company,array $item,array &$connections): bool
    {
        if (!isset($item['sourceConnection'])) return true;
        $channel = $item['channel'];
        if (!array_key_exists($channel,$connections)) {
            $provider = array_search($channel,CustomerMarketplaces::PROVIDERS,true);
            $connections[$channel] = $provider === false ? null : (new UpcomingPayoutStore($this->db))->connection($company,$provider);
        }
        return $connections[$channel] === $item['sourceConnection'];
    }

    public function pendingRevision(int $company, string $revision): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$revision)) return null;
        $record = $this->db->one('SELECT * FROM upcoming_payout_reviews WHERE company_id=? AND pending_revision=?',
            [$company,$revision]);
        return $record ? self::change($record) : null;
    }

    public function approve(array $actor, int $company, string $key, string $revision,
        ?callable $latest = null, ?callable $notify = null,bool $manual=false): array
    {
        Security::admin($actor);
        if (!preg_match('/^[a-f0-9]{64}$/D',$key) || !preg_match('/^[a-f0-9]{32}$/D',$revision)) {
            Http::fail(400,'Hakediş değişikliği tanımlanamadı.');
        }
        $accepted = $this->withCompany($company,function () use ($actor,$company,$key,$revision,$latest,$notify,$manual): bool {
            $snapshot = $latest ? $latest() : ['today'=>self::today(),'items'=>[]];
            $seen = $latest ? $this->reconcile($company,$snapshot,$notify,!$manual) : [];
            $record = $this->db->one('SELECT * FROM upcoming_payout_reviews WHERE company_id=? AND payout_key=?',
                [$company,$key]);
            if (!$record || (int)$record['settled'] === 1) return false;
            if ($record['pending_revision'] === null) return $record['applied_revision'] === $revision;
            if ($record['pending_revision'] !== $revision
                || (!$manual && $record['payment_date'] >= $snapshot['today'] && !isset($seen[$key]))) return false;
            $items=self::rows($record['pending_payload']);
            $connections=[];
            if(!$this->matchesConnection($company,$items[0],$connections))return false;
            if($manual)foreach($items as &$item){
                unset($item['sourceUnavailable']);
                $item['manualAmountApproval']=['at'=>gmdate('c'),'userId'=>$actor['id']];
            }
            unset($item);
            $acceptedPayload=self::json($items);
            $this->db->run('UPDATE upcoming_payout_reviews SET approved_payload=?, applied_revision=?,
                pending_payload=NULL, pending_revision=NULL, detected_at=NULL WHERE company_id=? AND payout_key=?',
                [$acceptedPayload,$revision,$company,$key]);
            (new UpcomingPayoutAdjustment($this->db))->supersedeEdits(
                $company,self::rows($record['approved_payload']),self::rows($record['pending_payload']));
            $this->db->run('INSERT INTO audit(user_id,company_id,action,before_value,after_value,created) VALUES(?,?,?,?,?,?)',
                [$actor['id'],$company,$manual?'Hakediş tutarı manuel onaylandı':'Pazaryeri hakediş tutarı onaylandı',$record['approved_payload'],$acceptedPayload,gmdate('c')]);
            return true;
        });
        // A newly discovered change is still saved if a previously opened confirmation became stale.
        if (!$accepted) Http::fail(409,'Hakedişin güncel tutarını doğrulayıp tekrar deneyin.');
        return ['approved'=>true,'key'=>$key,'revision'=>$revision];
    }

    private function withCompany(int $company, callable $action): mixed
    {
        $write=function () use ($company,$action) {
            if (!$this->db->mysql()) {
                // SQLite has a single writer. Enter its write transaction before reading a snapshot.
                $this->db->run('UPDATE upcoming_payout_reviews SET settled=settled WHERE 1=0');
            }
            if (!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company])) {
                Http::fail(404,'Firma bulunamadı.');
            }
            return $action();
        };
        return $this->db->pdo->inTransaction()?$write():TransactionRetry::run($this->db,$write);
    }

    private function snapshots(int $company): array
    {
        $rows = $this->db->all('SELECT * FROM upcoming_payout_reviews WHERE company_id=?
            ORDER BY payment_date,channel,payout_key',[$company]);
        return array_column($rows,null,'payout_key');
    }

    private function reconcile(int $company, array $snapshot, ?callable $notify,bool $autoRecover=true): array
    {
        $today = $snapshot['today'];
        $incoming = self::groupRows($snapshot['items'],$today);
        $existing = $this->snapshots($company);
        $settled = [];
        foreach ($snapshot['settled'] ?? [] as $receipt) {
            $settled[self::key($receipt['channel'],$receipt['date'])] = true;
        }
        foreach ($existing as $key => $record) {
            if (isset($incoming[$key])) continue;
            $prior = self::rows($record['approved_payload']);
            if (isset($settled[$key])) {
                if ((int)$record['settled'] !== 1) {
                    $this->db->run('UPDATE upcoming_payout_reviews SET settled=1, pending_payload=NULL,
                        pending_revision=NULL, detected_at=NULL, applied_revision=NULL WHERE company_id=? AND payout_key=?',
                        [$company,$key]);
                }
            } elseif ($record['payment_date'] >= $today && !self::automatic($prior)) {
                $this->db->run('DELETE FROM upcoming_payout_reviews WHERE company_id=? AND payout_key=?',[$company,$key]);
            } elseif($record['pending_payload']!==null && $record['payment_date'] >= $today){
                $pending=self::rows($record['pending_payload']);
                if(empty($pending[0]['sourceUnavailable'])){
                    foreach($pending as &$item)$item['sourceUnavailable']=true;
                    unset($item);
                    $this->db->run('UPDATE upcoming_payout_reviews SET pending_payload=? WHERE company_id=? AND payout_key=?',
                        [self::json($pending),$company,$key]);
                }
            }
        }
        $seen = [];
        foreach ($incoming as $key => $items) {
            $record = $existing[$key] ?? null;
            $after = self::json($items);
            if (!$record) {
                if (self::cents($items) === 0) continue;
                $this->db->run('INSERT INTO upcoming_payout_reviews(company_id,payout_key,channel,payment_date,approved_payload)
                    VALUES(?,?,?,?,?)',[$company,$key,$items[0]['channel'],$items[0]['date'],$after]);
                continue;
            }
            $before = self::rows($record['approved_payload']);
            if(!empty($before[0]['manualAmountApproval'])&&self::automatic($items))continue;
            $pending = $record['pending_payload'] ? self::rows($record['pending_payload']) : [];
            $freshest = $pending ?: $before;
            if (self::olderObservation($freshest,$items)) continue;
            if(!empty($pending[0]['sourceUnavailable'])&&$items[0]['source']==='marketplace'
                &&($items[0]['observedAt']??0)<=($pending[0]['observedAt']??0))continue;
            if($autoRecover&&!empty($pending[0]['sourceUnavailable'])&&$items[0]['source']==='marketplace'
                &&isset($items[0]['observedAt'],$pending[0]['observedAt'])
                &&$items[0]['observedAt']>$pending[0]['observedAt']
                &&!UpcomingPayoutReviewPolicy::hasEdits($this->db,$company,[...$before,...$pending])){
                $this->db->run('UPDATE upcoming_payout_reviews SET approved_payload=?,pending_payload=NULL,pending_revision=NULL,
                    detected_at=NULL,applied_revision=NULL,settled=0 WHERE company_id=? AND payout_key=?',
                    [$after,$company,$key]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,before_value,after_value,created) VALUES(NULL,?,?,?,?,?)',
                    [$company,'Hakediş tutarı güncel aktarım ile doğrulandı',$record['approved_payload'],$after,gmdate('c')]);
                continue;
            }
            if (!self::automatic($items) && $items[0]['verification'] !== 'confirmed' && !isset($settled[$key])) {
                // An unchanged fallback may indicate a missing marketplace response.
                if (self::automatic($before) || ($pending && $record['approved_payload'] === $after)) continue;
            }
            $replacesManualEstimate = !self::automatic($before) && $before[0]['verification'] === 'estimated'
                && $items[0]['source'] === 'marketplace' && isset($items[0]['observedAt']) && self::cents($items)>0;
            if (self::cents($before) === self::cents($items) || !self::automatic($items) || $replacesManualEstimate) {
                if ($record['approved_payload'] !== $after || $record['pending_revision'] !== null || (int)$record['settled'] === 1) {
                    $applied = self::cents($before) === self::cents($items) ? $record['applied_revision'] : null;
                    $this->db->run('UPDATE upcoming_payout_reviews SET approved_payload=?, pending_payload=NULL,
                        pending_revision=NULL, detected_at=NULL, applied_revision=?, settled=0 WHERE company_id=? AND payout_key=?',
                        [$after,$applied,$company,$key]);
                }
                continue;
            }
            $seen[$key] = true;
            if ($pending && self::cents($pending) === self::cents($items)) {
                if ($record['pending_payload'] !== $after) {
                    $this->db->run('UPDATE upcoming_payout_reviews SET pending_payload=? WHERE company_id=? AND payout_key=?',
                        [$after,$company,$key]);
                }
                continue;
            }
            $revision = bin2hex(random_bytes(16));
            $detected = gmdate('c');
            $this->db->run('UPDATE upcoming_payout_reviews SET pending_payload=?, pending_revision=?, detected_at=?,
                applied_revision=NULL, settled=0 WHERE company_id=? AND payout_key=?',
                [$after,$revision,$detected,$company,$key]);
            if ($notify) $notify($company,self::change([...$record,'pending_payload'=>$after,
                'pending_revision'=>$revision,'detected_at'=>$detected]));
        }
        return $seen;
    }

    private static function groupRows(array $items, string $today): array
    {
        $groups = [];
        foreach ($items as $item) {
            if (!is_int($item['amountCents']) || $item['amountCents']<0 || $item['date'] < $today) continue;
            $groups[self::key($item['channel'],$item['date'])][] = $item;
        }
        foreach ($groups as &$group) {
            usort($group,static fn(array $a,array $b): int => strcmp((string)$a['id'],(string)$b['id']));
        }
        return $groups;
    }

    /** The canonical source selector provides either automatic or manual lines for each day. */
    private static function automatic(array $items): bool
    {
        return in_array($items[0]['source'],['marketplace','marketplace_panel'],true);
    }

    private static function olderObservation(array $previous, array $incoming): bool
    {
        if ($previous[0]['source'] !== $incoming[0]['source']) {
            // A saved panel reading must not supersede a newer live observation on a retry.
            if ($previous[0]['source'] !== 'marketplace' || $incoming[0]['source'] !== 'marketplace_panel'
                || !isset($previous[0]['observedAt'],$incoming[0]['observedDate'])) return false;
            $day = (new \DateTimeImmutable('@'.$previous[0]['observedAt']))
                ->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
            return $incoming[0]['observedDate'] <= $day;
        }
        foreach (['observedAt','observedDate'] as $field) {
            if (isset($previous[0][$field],$incoming[0][$field]) && $incoming[0][$field] < $previous[0][$field]) return true;
        }
        return false;
    }

    private static function change(array $record): array
    {
        return ['key'=>$record['payout_key'],'revision'=>$record['pending_revision'],'channel'=>$record['channel'],
            'date'=>$record['payment_date'],'beforeCents'=>self::cents(self::rows($record['approved_payload'])),
            'proposedCents'=>self::cents(self::rows($record['pending_payload'])),'detectedAt'=>$record['detected_at'],
            ...(!empty(self::rows($record['pending_payload'])[0]['sourceUnavailable'])?['sourceUnavailable'=>true]:[])];
    }

    private static function key(string $channel,string $date): string { return hash('sha256',$channel."\0".$date); }
    private static function cents(array $items): int { return array_sum(array_column($items,'amountCents')); }
    private static function rows(string $payload): array { return json_decode($payload,true,16,JSON_THROW_ON_ERROR); }
    private static function json(array $rows): string { return json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
    private static function today(): string
    {
        return (new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    }
}
