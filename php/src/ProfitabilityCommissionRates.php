<?php
declare(strict_types=1);
namespace Omnigo;

final class ProfitabilityCommissionRates
{
    private const KIND = 'profitability_commission_rate';

    public function __construct(private Database $db) {}

    public function all(int $company): array
    {
        $rates = array_map(static fn(array $row): array => json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR),
            $this->db->all('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,self::KIND]));
        usort($rates,static fn(array $a,array $b): int => [$b['effectiveFrom'],$a['channel'],$a['sku']] <=> [$a['effectiveFrom'],$b['channel'],$b['sku']]);
        return $rates;
    }

    public function save(int $company,array $data): array
    {
        $channel = Validation::choice($data['channel']??null,array_values(OrderList::PROVIDERS));
        MarketplaceVisibility::requireVisible($channel);
        $sku = self::sku(Validation::text($data['sku']??null,'SKU',180,true));
        $effectiveFrom = Validation::date($data['effectiveFrom']??null);
        $points = $data['basisPoints']??null;
        if (!is_int($points)||$points<0||$points>10000) Http::fail(400,'0 ile 100 arasında bir komisyon yüzdesi girin.');
        $rate = ['channel'=>$channel,'sku'=>$sku,'effectiveFrom'=>$effectiveFrom,'basisPoints'=>$points];
        return TransactionRetry::run($this->db,function () use ($company,$rate): array {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $json = json_encode($rate,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            foreach ($this->db->all('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,self::KIND]) as $row) {
                $existing = json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
                if ($existing['channel']!==$rate['channel']||$existing['sku']!==$rate['sku']||$existing['effectiveFrom']!==$rate['effectiveFrom']) continue;
                if ($row['payload']!==$json) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=? AND company_id=?',
                    [$json,gmdate('c'),$row['id'],$company]);
                return $rate;
            }
            $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,self::KIND,$json,gmdate('c')]);
            return $rate;
        });
    }

    public static function sku(string $value): string
    {
        return mb_strtoupper(trim($value),'UTF-8');
    }
}
