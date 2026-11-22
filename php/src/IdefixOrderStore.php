<?php
declare(strict_types=1);
namespace Omnigo;

/** Cursor and financial staging commit atomically; personal details use the encrypted delivery store. */
final class IdefixOrderStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries){}
    public function state(int $company): ?array{return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_idefix'",[$company]);}
    public function commit(int $company,?array $before,array $config,array $state,array $mapped,bool $reset,bool $complete,array $claims=[],?array $requests=null): void
    {
        $this->db->transaction(function()use($company,$before,$config,$state,$mapped,$reset,$complete,$claims,$requests){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$current=$this->state($company);$fresh=$this->vault->get($company,'idefix');
            if(($current['version']??0)!==($before['version']??0)||($fresh['revision']??null)!==$config['revision'])throw new SyncFailure(409);
            if($reset)$this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_idefix'",[$company]);
            ReconciliationReference::page($state,array_column($mapped,'delivery'),$reset);
            foreach($mapped as $item)if(!$this->deliveries->ingestIsolated($company,'idefix',$item['delivery']))$state['scan']['isolatedOrder']=true;
            if($mapped)$this->save($company,'stage_idefix',array_column($mapped,'order'));
            if($claims||$requests!==null)$this->save($company,'stage_idefix',['refunds'=>$claims,'requests'=>$requests]);
            if((new OrderImportQueue($this->db))->holdSnapshot($company,'idefix',$state,$complete))$complete=false;
            if($complete){
                unset($state['financialSnapshotHeld']);
                $packages=[];$returns=[];$requestItems=[];$requestsComplete=true;$requestPages=0;
                foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='stage_idefix' ORDER BY id",[$company]) as $row){
                    $batch=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                    if(isset($batch['refunds'])){
                        array_push($returns,...$batch['refunds']);$requestPages++;
                        if(is_array($batch['requests']??null))array_push($requestItems,...$batch['requests']);else $requestsComplete=false;
                    }else array_push($packages,...$batch);
                }
                $refundsComplete=($state['scan']['stream']??'')==='returns'&&isset($state['scan']['claimTotal'])&&($state['scan']['claimCount']??-1)===$state['scan']['claimTotal'];
                $orders=IdefixOrderMapper::combine($packages);
                if($refundsComplete)$orders=IdefixReturns::apply($orders,$returns);
                if($refundsComplete&&$requestsComplete&&$requestPages===max(1,$state['scan']['claimPages']??1))ReturnRequestSnapshot::publish($state,$requestItems,$state['scan']['from'],$state['scan']['end']);
                ReconciliationReference::complete($state,$orders,$state['scan']['from'],$state['scan']['end']);
                $existing=[];
                foreach($this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='order_idefix'",[$company]) as $row){$order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$existing[$order['externalId']]=$row;}
                foreach($orders as $order){$old=$existing[$order['externalId']]??null;$this->save($company,'order_idefix',$order,$old['id']??null);unset($existing[$order['externalId']]);}
                // Only remove our obsolete snapshot entries after the entire range succeeds.
                foreach($existing as $old)$this->db->run("DELETE FROM records WHERE id=? AND company_id=? AND kind='order_idefix'",[$old['id'],$company]);
                $state['startDate']=$state['scan']['from'];$state['watermark']=$state['scan']['end'];$state['lastSuccessAt']=gmdate('c');$state['phase']='ready';$state['ordersPublished']=true;$state['orderCount']=count($orders);$state['sampleOnly']=false;$state['salesPublished']=false;
                $state['refundsVerified']=$refundsComplete;
                if($refundsComplete)$state['refundsAsOf']=$state['scan']['end'];else unset($state['refundsAsOf']);
                unset($state['scan'],$state['code'],$state['sampleCount']);$this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_idefix'",[$company]);
                IntegrationImportEvidence::completed($state,$config,true);
            }
            $state['checkedAt']=gmdate('c');$this->save($company,'sync_idefix',$state,$current['id']??null);
        });
    }
    private function save(int $company,string $kind,array $value,?int $id=null): void
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($id)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$json,gmdate('c'),$id]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$json,gmdate('c')]);
    }
}
