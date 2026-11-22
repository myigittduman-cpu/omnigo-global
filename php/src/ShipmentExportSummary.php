<?php
declare(strict_types=1);
namespace Omnigo;
final class ShipmentExportSummary
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function read(int $company,array $items): array
    {
        $orders=[];$parts=array_sum(array_column($items,'quantity'));$unmatched=0;
        foreach(array_chunk(array_column($items,'id'),200) as $batch){
            $rows=$this->db->all('SELECT id,provider,payload FROM shipment_entries WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($batch),'?')).')',[$company,...$batch]);
            $payloads=[];$links=[];$identities=[];
            foreach($rows as $row){
                $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);$payloads[$row['id']]=$payload;
                $external=$payload['sourceDeliveryId']??($payload['sourceDeliveryIds'][0]??null);
                if($external)$links[$row['provider']][$external]=true;
            }
            foreach($links as $provider=>$externalIds){
                $keys=array_keys($externalIds);
                $deliveries=$this->db->all('SELECT external_id,payload FROM delivery_orders WHERE company_id=? AND provider=? AND external_id IN ('.implode(',',array_fill(0,count($keys),'?')).')',[$company,$provider,...$keys]);
                foreach($deliveries as $delivery){
                    $value=$this->cipher->open($company,'order:'.$provider.':'.$delivery['external_id'],$delivery['payload']);
                    $identities[$provider][$delivery['external_id']]=$value['orderNumber']??null;
                }
            }
            foreach($rows as $row){
                $payload=$payloads[$row['id']];$identity=null;
                $external=$payload['sourceDeliveryId']??($payload['sourceDeliveryIds'][0]??null);
                if($external)$identity=$identities[$row['provider']][$external]??null;
                if(!$identity){$identity='manual:'.$row['id'];$unmatched++;}
                $orders[$row['provider'].':'.$identity]=true;
            }
        }
        return ['orderCount'=>count($orders),'productCount'=>$parts,'unmatchedRows'=>$unmatched];
    }
}
