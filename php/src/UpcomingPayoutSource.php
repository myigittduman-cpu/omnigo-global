<?php
declare(strict_types=1);
namespace Omnigo;

/** Browser readers use existing authenticated seller pages and submit only dated net amounts. */
final class UpcomingPayoutSource
{
    public const PANEL_CHANNELS = [
        'pttavm' => ['channel'=>'PTT AVM','shop'=>'Vizyona'],
        'idefix' => ['channel'=>'idefix','shop'=>'Vizyona'],
        'koctas' => ['channel'=>'Koçtaş','shop'=>'Vizyona'],
    ];

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
    ) {}

    public function configured(int $company): array
    {
        $store = new UpcomingPayoutStore($this->db);
        if ($company !== $store->pilot()) return [];
        $channels = (new Repository($this->db))->records($company,'channels',['role'=>'admin']);
        $active = array_column(array_filter($channels,static fn(array $row): bool => $row['status']==='active'),'name');
        $sources = [];
        foreach (self::PANEL_CHANNELS as $provider => $info) {
            if (!in_array($info['channel'],$active,true)) continue;
            $settings = $this->vault->get($company,$provider);
            if (empty($settings['enabled'])) continue;
            $identity = $provider === 'idefix' ? (string)($settings['vendorId'] ?? '') : $info['shop'];
            $connection = $store->connection($company,$provider);
            if ($identity === '' || $connection === null) continue;
            $sources[$provider] = compact('identity','connection');
        }
        return $sources;
    }

    public function receive(int $company,string $provider,string $identity,string $connection,array $rows,int $observedAt): array
    {
        if (!isset(self::PANEL_CHANNELS[$provider]) || count($rows)>120 || !array_is_list($rows)) {
            Http::fail(400,'Hakediş kaynağı veya kayıt listesi geçersiz.');
        }
        $now = time();
        if ($observedAt > $now+30 || $observedAt < $now-360) Http::fail(409,'Ödeme ekranını tekrar kontrol edin.');
        $day = new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul'));
        $from = $day->format('Y-m-d');
        $through = $day->modify('+180 days')->format('Y-m-d');
        $days = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass) Http::fail(400,'Hakediş satırı geçersiz.');
            $date = Validation::date($row->date ?? null);
            $cents = Validation::integer($row->amountCents ?? null);
            if ($date < $from || $date > $through || isset($days[$date])) {
                Http::fail(422,'Ödeme tarihi veya yinelenen satır kontrolü başarısız.');
            }
            $days[$date] = $cents;
        }
        ksort($days);

        return TransactionRetry::run($this->db,function () use ($company,$provider,$identity,$connection,$days,$observedAt): array {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $expected = $this->configured($company)[$provider] ?? null;
            if ($expected === null || !hash_equals($expected['identity'],$identity)) Http::fail(403,'Satıcı hesabı doğrulanamadı.');
            if ($expected['connection'] !== $connection) Http::fail(409,'Pazaryeri bağlantısı değişti.');
            $finance = new FinanceStore($this->db);
            $key = UpcomingPayoutAdapter::stateKey($provider);
            $before = $finance->state($company,$key);
            $previous = json_decode($before['payload'] ?? '{}',true,32,JSON_THROW_ON_ERROR)['published'] ?? null;
            if (($previous['connection'] ?? null) === $connection && ($previous['checkedAt'] ?? 0) >= $observedAt) {
                return ['accepted'=>false,'count'=>count(array_filter($previous['days'] ?? []))];
            }
            $finance->writeState($company,$key,[
                'published'=>['connection'=>$connection,'checkedAt'=>min(time(),$observedAt),'days'=>$days],
            ],$before);
            return ['accepted'=>true,'count'=>count(array_filter($days))];
        });
    }
}
