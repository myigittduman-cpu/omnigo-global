<?php
declare(strict_types=1);
namespace Omnigo;

/** One transaction covers the order index, encrypted documents, shipment rows and scan cursor. */
final class HepsiburadaOrderStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries){}
    public function commit(int $company,array $config,?array $before,array $state,array $orders):void
    {
        $this->db->transaction(function()use($company,$config,$before,$state,$orders){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);
            if(($current['version']??0)!==($before['version']??0)||($this->vault->get($company,'hepsiburada')['revision']??null)!==($config['revision']??null))throw new SyncFailure(409);
            $queue=new OrderImportQueue($this->db);
            foreach($orders as $mapped)$queue->attempt($company,'hepsiburada',$mapped['order']['externalId'],function()use($company,$mapped,$config){
                $order=$mapped['order'];$row=$this->db->one("SELECT id,payload FROM recent_orders WHERE company_id=? AND provider='hepsiburada' AND external_id=?",[$company,$order['externalId']]);
                $old=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];$byId=array_column($order['products'],null,'lineId');
                foreach($old['products']??[] as $line){
                    $new=$byId[$line['lineId']]??null;
                    // Never resurrect a cancellation or replace a full snapshot with an older/partial one.
                    if(!$new||strtotime($new['sourceUpdatedAt'])<strtotime($line['sourceUpdatedAt'])||$new['cancelledQuantity']<$line['cancelledQuantity'])throw new OrderSnapshotConflict();
                }
                $notify=(bool)$row||strtotime($order['createdAt'])>=(int)($config['notifyFrom']??0);
                foreach($mapped['deliveries'] as $delivery)$this->deliveries->ingest($company,$delivery,$notify);
                $payload=json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if(($row['payload']??null)===$payload)return;
                if($row)$this->db->run('UPDATE recent_orders SET payload=?,updated=? WHERE id=?',[$payload,gmdate('c'),$row['id']]);
                else $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'hepsiburada',$order['externalId'],$payload,gmdate('c')]);
            });
            $state['isolatedOrderCount']=$queue->count($company,'hepsiburada');
            $payload=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_hepsiburada',$payload,gmdate('c')]);
        });
    }
}
