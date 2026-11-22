<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded page per job; thread saves are idempotent so retries are safe. */
final class KoctasQuestionAdapter implements ScheduledReadAdapter
{
    private int $delay = 65;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private ?KoctasInboxClient $client = null
    ) {}

    public function nextDelay(): int { return $this->delay; }

    public function syncReadOnly(int $companyId, int $deadline): void
    {
        $this->delay = 65;
        $config = $this->vault->get($companyId, 'koctas');
        if (!$config || empty($config['enabled'])) throw new SyncFailure(403);

        $row   = $this->state($companyId);
        $state = $row ? json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR) : [];
        if (($state['nextRequestAt'] ?? 0) > time()) throw new SyncFailure(429, $state['nextRequestAt'] - time());

        $revision = $config['revision'] ?? null;
        // A config change resets the cursor so the full thread list is re-scanned.
        $cursor = ($state['revision'] ?? null) === $revision ? ($state['cursor'] ?? null) : null;

        try {
            $client  = $this->client ?? new KoctasInboxClient($config['apiKey'], (int)$config['shopId']);
            $result  = $client->threads($cursor, $deadline);
            if (!is_array($result['data'] ?? null)) throw new SyncFailure(502);

            $threads = [];
            foreach ($result['data'] as $raw) {
                $thread = InboxMapping::thread($raw);
                if ($thread) $threads[] = $thread;
            }

            $nextCursor = $result['next_page_token'] ?? null;
            $complete   = $nextCursor === null;

            $next = [
                'revision'      => $revision,
                'cursor'        => $complete ? null : $nextCursor,
                'phase'         => $complete ? 'ready' : 'syncing',
                'lastPageAt'    => gmdate('c'),
                'nextRequestAt' => time() + 65,
            ];
            if ($complete) {
                $next['lastSuccessAt'] = gmdate('c');
                unset($next['cursor']);
                $this->delay = 300;
            } elseif (isset($state['lastSuccessAt'])) {
                $next['lastSuccessAt'] = $state['lastSuccessAt'];
            }
            unset($next['failure']);

            $this->commit($companyId, $row, $revision, $next, $threads);
        } catch (\Throwable $error) {
            $state['failure'] = ['code' => $error instanceof SyncFailure ? $error->httpStatus : 0];
            $this->commit($companyId, $row, $revision, $state, []);
            throw $error;
        }
    }

    private function state(int $company): ?array
    {
        return $this->db->one(
            "SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_koctas_questions'",
            [$company]
        );
    }

    private function commit(int $company, ?array $before, ?string $revision, array $next, array $threads): void
    {
        $this->db->transaction(function () use ($company, $before, $revision, $next, $threads) {
            $this->db->one('SELECT id FROM companies WHERE id=?' . $this->db->lock(), [$company]);
            $current = $this->state($company);
            $fresh   = $this->vault->get($company, 'koctas');
            if (($current['version'] ?? 0) !== ($before['version'] ?? 0)
                || ($fresh['revision'] ?? null) !== $revision) {
                throw new SyncFailure(409);
            }
            $repo = new InboxRepository($this->db);
            foreach ($threads as $thread) $repo->save($company, $thread, 'koctas');
            $payload = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($current) {
                $this->db->run(
                    'UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',
                    [$payload, gmdate('c'), $current['id']]
                );
            } else {
                $this->db->run(
                    'INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',
                    [$company, 'sync_koctas_questions', $payload, gmdate('c')]
                );
            }
        });
    }

    public static function schedule(Database $db, IntegrationVault $vault, SyncQueue $queue): void
    {
        if (!(new InboxRepository($db))->ready()) return;
        foreach ($db->all("SELECT company_id FROM records WHERE kind='integration_koctas'") as $row) {
            $company = (int)$row['company_id'];
            $config  = $vault->get($company, 'koctas');
            if (!empty($config['enabled'])) {
                $queue->enqueue($company, 'Koçtaş Sorular');
            }
        }
    }
}
