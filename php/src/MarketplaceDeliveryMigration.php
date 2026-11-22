<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded, idempotent upgrade to one completed status; physical delivery and source data stay intact. */
final class MarketplaceDeliveryMigration
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $company,bool $dryRun=false): array
    {
        $result=['changed'=>0,'byProvider'=>[]];$cursor=0;
        do{
            $batch=$this->db->all("SELECT id FROM shipment_entries WHERE company_id=? AND id>? AND (source_key IS NOT NULL OR row_status='delivered') AND removed_at IS NULL ORDER BY id LIMIT 100",[$company,$cursor]);
            if(!$batch)break;
            $delta=TransactionRetry::run($this->db,function()use($company,$batch,$dryRun){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $counts=['changed'=>0,'byProvider'=>[]];$links=[];
                foreach($batch as $item){
                    $id=(int)$item['id'];$row=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$id]);
                    if(!$row||$row['removed_at']!==null)continue;
                    $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
                    $legacyDelivered=ShipmentStatus::resolve($row)==='delivered';
                    if(!$legacyDelivered&&(!$row['source_key']||ShipmentSourceStatus::next($row,$payload,$payload,false)!=='completed'))continue;
                    $counts['changed']++;$counts['byProvider'][$row['provider']]=($counts['byProvider'][$row['provider']]??0)+1;
                    if($dryRun)continue;
                    $now=gmdate('c');
                    $this->db->run("UPDATE shipment_entries SET row_status='completed',shipped=0,cancelled=0,version=version+1,updated=? WHERE company_id=? AND id=?",[$now,$company,$id]);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',[$company,'shipment.completion_upgrade',$id,json_encode(['rowStatus'=>ShipmentStatus::resolve($row)]),json_encode(['rowStatus'=>'completed','basis'=>$legacyDelivered?'existing_delivery':'marketplace']),$now]);
                    foreach(array_filter([...($payload['sourceDeliveryIds']??[]),$payload['sourceDeliveryId']??null]) as $external)$links[$row['provider']][$external]=true;
                }
                foreach($links as $provider=>$externalIds)foreach(array_keys($externalIds) as $external){
                    $this->db->run('UPDATE delivery_orders SET version=version+1,document_id=NULL,updated=? WHERE company_id=? AND provider=? AND external_id=?',[gmdate('c'),$company,$provider,$external]);
                }
                return $counts;
            });
            $result['changed']+=$delta['changed'];
            foreach($delta['byProvider'] as $provider=>$count)$result['byProvider'][$provider]=($result['byProvider'][$provider]??0)+$count;
            $cursor=(int)end($batch)['id'];
        }while(count($batch)===100);
        return $result;
    }
}
