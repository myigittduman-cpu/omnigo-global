<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit, idempotent catalog additions; never modifies existing store settings. */
final class MarketplaceCatalog
{
    public function __construct(private Database $db) {}

    public function addMissing(int $company, array $names): array
    {
        foreach ($names as $name) {
            if (!in_array($name, Repository::CHANNELS, true)) throw new \InvalidArgumentException('Pazaryeri tanımlı değil.');
        }
        return $this->db->transaction(function() use ($company, $names): array {
            if (!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(), [$company])) {
                throw new \RuntimeException('Müşteri bulunamadı.');
            }
            $existing=[];
            foreach ($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='channels'", [$company]) as $row) {
                $existing[]=json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR)['name'];
            }
            $added=[];
            foreach (array_unique($names) as $name) {
                if (in_array($name, $existing, true)) continue;
                $payload=json_encode(['name'=>$name,'status'=>'active','storeName'=>'','connection'=>'not_connected'], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)', [$company,'channels',$payload,gmdate('c')]);
                $added[]=['id'=>$this->db->insertId(),'name'=>$name];
            }
            return $added;
        });
    }
}
