<?php
declare(strict_types=1);
namespace Omnigo;

/** PII is encrypted by DeliveryRepository; the generic order index holds only sales data. */
final class PttavmOrderStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries){}
    public function state(int $company): ?array{return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_pttavm'",[$company]);}
    public function commit(int $company,array $config,?array $before,array $state,array $mapped): void
    {
        $write=function()use($company,$config,$before,$state,$mapped){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->state($company);$fresh=$this->vault->get($company,'pttavm');
            if(empty($fresh['enabled'])||($fresh['revision']??null)!==$config['revision']||($current['version']??0)!==($before['version']??0))throw new SyncFailure(409);
            foreach($mapped as $item){
                $order=$item['order'];$delivery=$item['delivery'];
                $existing=$this->db->one("SELECT id,payload FROM recent_orders WHERE company_id=? AND provider='pttavm' AND external_id=?",[$company,$order['externalId']]);
                if($existing)$order=PttavmCouponDetail::preserve($order,json_decode($existing['payload'],true,64,JSON_THROW_ON_ERROR));
                // Historic table rows are restored only after the connection audit permits backfill.
                if(!empty($config['restoreShipments']))foreach($delivery['shipmentItems'] as &$line)$line['restoreMissing']=true;
                unset($line);
                $notify=$existing!==null||strtotime($delivery['createdAt'])>=(int)($config['connectedAt']??PHP_INT_MAX);
                $this->deliveries->ingest($company,$delivery,$notify);
                $payload=json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if(($existing['payload']??null)===$payload)continue;
                if($existing)$this->db->run('UPDATE recent_orders SET payload=?,updated=? WHERE id=?',[$payload,gmdate('c'),$existing['id']]);
                else $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'pttavm',$order['externalId'],$payload,gmdate('c')]);
            }
            $state['orderCount']=(int)$this->db->one("SELECT COUNT(*) n FROM recent_orders WHERE company_id=? AND provider='pttavm'",[$company])['n'];
            $payload=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_pttavm',$payload,gmdate('c')]);
        };
        if($this->db->pdo->inTransaction())$write();else TransactionRetry::run($this->db,$write);
    }
}
