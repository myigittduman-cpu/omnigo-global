<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable conflict markers; fresh API scans retry these records, never stale payloads. */
final class OrderImportQueue
{
    public function __construct(private Database $db){}
    /** Caller holds the company lock and transaction. Unexpected failures still abort the page. */
    public function attempt(int $company,string $provider,string $externalId,callable $write): bool
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Sipariş işlemi bir işlem kilidi gerektirir.');
        $this->db->run('SAVEPOINT order_import');
        try{$write();}
        catch(OrderSnapshotConflict){
            $this->db->run('ROLLBACK TO SAVEPOINT order_import');$this->db->run('RELEASE SAVEPOINT order_import');
            $row=$this->find($company,$provider,$externalId);$old=$row?json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR):[];
            $value=['externalId'=>$externalId,'firstSeenAt'=>$old['firstSeenAt']??gmdate('c'),'lastSeenAt'=>gmdate('c'),'attempts'=>min(1000000,($old['attempts']??0)+1),'reason'=>'older_snapshot','nextAttemptAt'=>time()+60];
            $payload=json_encode($value,JSON_THROW_ON_ERROR);
            if($row)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$row['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'order_retry_'.$provider,$payload,gmdate('c')]);
            return false;
        }
        $this->db->run('RELEASE SAVEPOINT order_import');
        if($row=$this->find($company,$provider,$externalId))$this->db->run('DELETE FROM records WHERE id=? AND company_id=?',[$row['id'],$company]);
        return true;
    }
    public function pending(int $company,string $provider): array
    {
        return array_map(fn($r)=>json_decode($r['payload'],true,16,JSON_THROW_ON_ERROR),$this->db->all('SELECT payload FROM records WHERE company_id=? AND kind=? ORDER BY updated,id',[$company,'order_retry_'.$provider]));
    }
    public function count(int $company,string $provider): int
    {
        return (int)$this->db->one('SELECT COUNT(*) n FROM records WHERE company_id=? AND kind=?',[$company,'order_retry_'.$provider])['n'];
    }
    private function find(int $company,string $provider,string $externalId): ?array
    {
        foreach($this->db->all('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,'order_retry_'.$provider]) as $row)
            if(json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR)['externalId']===$externalId)return $row;
        return null;
    }
    /** Finish an incomplete financial scan without publishing contradictory amounts. */
    public function holdSnapshot(int $company,string $provider,array &$state,bool $complete): bool
    {
        if(!$complete||empty($state['scan']['isolatedOrder'])&&!$this->count($company,$provider))return false;
        $state['financialSnapshotHeld']=true;$state['checkedAt']=gmdate('c');
        unset($state['scan'],$state['reconciliationDraft']);
        $this->db->run('DELETE FROM records WHERE company_id=? AND kind=?',[$company,'stage_'.$provider]);
        return true;
    }
}
