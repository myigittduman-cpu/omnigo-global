<?php
declare(strict_types=1);
namespace Omnigo;

/** Holds only the supplemental order-history checkpoint for each company. */
final class PazaramaHistoryJobStore
{
    private const KEY='pazarama_history';

    public function __construct(private Database $db){}

    public function available(): bool
    {
        return IntegrationImportSchema::tableAvailable($this->db,'finance_sync','company_id');
    }

    private function lockedRow(int $company): ?array
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Geçmiş işini kilitlemek için veritabanı işlemi gerekiyor.');
        if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new SyncFailure(404);
        return $this->db->one('SELECT payload,version FROM finance_sync WHERE company_id=? AND provider=?'.$this->db->lock(),
            [$company,self::KEY]);
    }

    public function get(int $company,bool $lock=false): ?array
    {
        $row=$lock?$this->lockedRow($company):(new FinanceStore($this->db))->state($company,self::KEY);
        if(!$row)return null;
        $state=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
        return ['company_id'=>$company]+$state;
    }

    public function save(array $job): void
    {
        $company=(int)($job['company_id']??0);
        if($company<1)throw new \InvalidArgumentException('Arşiv işi için mağaza gerekiyor.');
        $before=$this->lockedRow($company);
        unset($job['company_id']);
        (new FinanceStore($this->db))->writeState($company,self::KEY,$job,$before);
    }

    public function due(int $now): ?int
    {
        $state=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.state'))":"json_extract(payload,'$.state')";
        $next=$this->db->mysql()?"CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.next_run')) AS SIGNED)":"CAST(json_extract(payload,'$.next_run') AS INTEGER)";
        $row=$this->db->one("SELECT company_id FROM finance_sync WHERE provider=? AND $state IN ('queued','running') AND $next<=?
            ORDER BY $next,company_id LIMIT 1",[self::KEY,$now]);
        return $row?(int)$row['company_id']:null;
    }
}
