<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit operator-requested closure of historical review rows; never changes source snapshots. */
final class HistoricalReviewCompletion
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $company,string $beforeMonth,bool $dryRun=true): array
    {
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$beforeMonth))throw new \InvalidArgumentException('Geçerli ay seçin.');
        $cutoff=(new \DateTimeImmutable($beforeMonth.'-01',new \DateTimeZone('Europe/Istanbul')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $result=['changed'=>0,'ids'=>[],'byProvider'=>[],'beforeMonth'=>$beforeMonth];$cursor=0;
        do{
            $batch=$this->db->all("SELECT id FROM shipment_entries WHERE company_id=? AND id>? AND removed_at IS NULL AND ordered_at<>'' AND ordered_at<? AND ".MarketplaceVisibility::sql().' ORDER BY id LIMIT 100',[$company,$cursor,$cutoff]);
            if(!$batch)break;
            $delta=TransactionRetry::run($this->db,function()use($company,$batch,$cutoff,$dryRun){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $changes=[];
                foreach($batch as $item){
                    $id=(int)$item['id'];$row=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$id]);
                    if(!$row||$row['removed_at']!==null||!$row['ordered_at']||$row['ordered_at']>=$cutoff)continue;
                    $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$view=ShipmentFulfillment::presentation($row,$payload);
                    if(!$view['fulfillmentNeedsReview']||!in_array($view['fulfillmentStatus'],['pending','shipped','review'],true)||$view['marketplaceStatus']==='cancelled')continue;
                    $changes[]=['id'=>$id,'provider'=>$row['provider']];if($dryRun)continue;
                    $now=gmdate('c');$before=['rowStatus'=>ShipmentStatus::resolve($row),'fulfillment'=>$payload['fulfillment']??null];
                    $payload['fulfillment']=['version'=>1,'confirmedSource'=>ShipmentFulfillment::source($payload),'confirmedAt'=>$now,'confirmedBy'=>'Yönetici talebiyle toplu tamamlama'];
                    $this->db->run("UPDATE shipment_entries SET row_status='completed',shipped=0,cancelled=0,payload=?,version=version+1,updated=? WHERE company_id=? AND id=?",[$this->cipher->seal($company,'shipment:'.$id,$payload),$now,$company,$id]);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',[$company,'shipment.historical_completion',$id,json_encode($before),json_encode(['rowStatus'=>'completed','basis'=>'operator_request','before'=>$cutoff]),$now]);
                    foreach(array_unique(array_filter([...($payload['sourceDeliveryIds']??[]),$payload['sourceDeliveryId']??null])) as $external)$this->db->run('UPDATE delivery_orders SET version=version+1,document_id=NULL,updated=? WHERE company_id=? AND provider=? AND external_id=?',[$now,$company,$row['provider'],$external]);
                }
                return $changes;
            });
            foreach($delta as $change){$result['changed']++;$result['ids'][]=$change['id'];$result['byProvider'][$change['provider']]=($result['byProvider'][$change['provider']]??0)+1;}
            $cursor=(int)end($batch)['id'];
        }while(count($batch)===100);
        return $result;
    }
}
