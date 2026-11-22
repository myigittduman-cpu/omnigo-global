<?php
declare(strict_types=1);
namespace Omnigo;

final class UpcomingPayoutSourceRoute
{
    public function __construct(private Database $db) {}

    public function handle(string $method,array $user): never
    {
        Security::admin($user);
        if (!PazaramaCommissionStore::available($this->db)) Http::fail(503,'Hakediş aktarımı şu anda kullanılamıyor.');
        $company = (new UpcomingPayoutStore($this->db))->pilot();
        if ($company === null) Http::fail(404,'Hakediş mağazası bulunamadı.');
        $source = new UpcomingPayoutSource($this->db,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key'));
        if ($method === 'GET') Http::json(['sources'=>(object)$source->configured($company)]);
        if ($method !== 'POST') Http::fail(405,'İşlem desteklenmiyor.');
        $data = Http::body(24000);
        $provider = Validation::choice($data['provider'] ?? null,array_keys(UpcomingPayoutSource::PANEL_CHANNELS));
        $identity = Validation::text($data['identity'] ?? null,'Satıcı kimliği',80);
        $connection = Validation::text($data['connection'] ?? null,'Pazaryeri bağlantısı',50);
        if (!is_int($data['observedAt'] ?? null) || !is_array($data['rows'] ?? null)) {
            Http::fail(400,'Okuma zamanı veya hakediş kayıtları geçersiz.');
        }
        Http::json($source->receive($company,$provider,$identity,$connection,$data['rows'],$data['observedAt']));
    }
}
