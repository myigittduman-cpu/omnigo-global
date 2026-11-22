<?php
declare(strict_types=1);
namespace Omnigo;

final class TrendyolOrderStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries){}
    public function state(int $company): ?array{return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_trendyol'",[$company]);}
    public function commit(int $company,array $config,?array $before,array $state,array $packages): void
    {
        TransactionRetry::run($this->db,function()use($company,$config,$before,$state,$packages){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $fresh=$this->vault->get($company,'trendyol');$current=$this->state($company);
            if(empty($fresh['enabled'])||($fresh['revision']??null)!==$config['revision']||($before['version']??0)!==($current['version']??0))throw new SyncFailure(409);
            $orders=[];
            foreach($packages as $package){
                $number=TrendyolOrderMapper::identity($package['orderNumber']??'');
                if(!isset($orders[$number])){
                    $row=$this->db->one("SELECT * FROM delivery_orders WHERE company_id=? AND provider='Trendyol' AND external_id=?",[$company,$number]);
                    $orders[$number]=['old'=>$row?$this->deliveries->decode($row):[],'known'=>(bool)$row];
                }
                $mapped=TrendyolOrderMapper::merge($package,$orders[$number]['old']);$orders[$number]['old']=$mapped['delivery'];$orders[$number]['mapped']=$mapped;
            }
            foreach($orders as $entry){
                $mapped=$entry['mapped'];$order=$mapped['order'];
                $notify=$entry['known']||strtotime($order['createdAt'])>=(int)($config['connectedAt']??PHP_INT_MAX);
                $this->deliveries->ingest($company,$mapped['delivery'],$notify);
                $row=$this->db->one("SELECT id,payload FROM recent_orders WHERE company_id=? AND provider='trendyol' AND external_id=?",[$company,$order['externalId']]);
                $payload=json_encode($order,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(($row['payload']??null)===$payload)continue;
                if($row)$this->db->run('UPDATE recent_orders SET payload=?,updated=? WHERE id=?',[$payload,gmdate('c'),$row['id']]);
                else $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'trendyol',$order['externalId'],$payload,gmdate('c')]);
            }
            $state['orderCount']=(int)$this->db->one("SELECT COUNT(*) n FROM recent_orders WHERE company_id=? AND provider='trendyol'",[$company])['n'];
            $payload=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_trendyol',$payload,gmdate('c')]);
        });
    }
}
