<?php
declare(strict_types=1);
namespace Omnigo;

/** Private financial projection in the existing indexed store; excluded by OrderList's provider whitelist. */
final class HepsiburadaSalesOrders
{
    public const PROVIDER='hepsiburada_sales';
    public function __construct(private Database $db){}
    public function save(int $company,array $order,string $through):void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Satış yayını işlem kilidi gerektirir.');
        $row=$this->db->one('SELECT id,payload FROM recent_orders WHERE company_id=? AND provider=? AND external_id=?',[$company,self::PROVIDER,$order['externalId']]);
        $json=json_encode($order,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(($row['payload']??null)===$json)return;
        if($row)$this->db->run('UPDATE recent_orders SET payload=?,updated=? WHERE id=?',[$json,$through,$row['id']]);
        else $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,self::PROVIDER,$order['externalId'],$json,$through]);
    }
    public function rows(int $company):\Generator
    {
        $statement=$this->db->run('SELECT payload FROM recent_orders WHERE company_id=? AND provider=? ORDER BY id',[$company,self::PROVIDER]);
        while($row=$statement->fetch())yield $row;
    }
    public function removeMissing(int $company,\DateTimeImmutable $from,\DateTimeImmutable $through,array $published):void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Satış yayını işlem kilidi gerektirir.');
        foreach($this->db->all('SELECT id,external_id,payload FROM recent_orders WHERE company_id=? AND provider=?',[$company,self::PROVIDER]) as $row){
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$date=new \DateTimeImmutable($order['createdAt']);
            if($date>=$from&&$date<=$through&&!isset($published[$row['external_id']]))$this->db->run('DELETE FROM recent_orders WHERE id=? AND provider=?',[$row['id'],self::PROVIDER]);
        }
    }
    public static function reportingState(array $state):array
    {
        // A pending/retried claims scan retains the last complete projection and its actual cutoff.
        $finance=$state['financial']??[];
        return [...$state,'watermark'=>!empty($finance['reportingReady'])?($finance['through']??null):null,'startDate'=>$finance['from']??null];
    }
}
