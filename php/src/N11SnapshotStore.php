<?php
declare(strict_types=1);
namespace Omnigo;

final class N11SnapshotStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?DeliveryRepository $deliveries=null,private ?ReturnBuyerIdentity $buyers=null) {}
    public function state(int $company): ?array
    {
        return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
    }
    public function commit(int $company,?array $before,array $config,array $state,array $page,bool $reset,bool $complete,array $deliveryItems=[]): void
    {
        $this->db->transaction(function() use($company,$before,$config,$state,$page,$reset,$complete,$deliveryItems) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->state($company);$fresh=$this->vault->get($company,'n11');
            if (($current['version']??0)!==($before['version']??0)||($fresh['revision']??null)!==($config['revision']??null)) throw new SyncFailure(409);
            ReconciliationReference::page($state,$deliveryItems,$reset);
            foreach($deliveryItems as $item)if($this->deliveries&&!$this->deliveries->ingestIsolated($company,'n11',$item))$state['scan']['isolatedOrder']=true;
            if($this->deliveries&&($page['type']??'')==='orders')$state['deliveryLastPageAt']=gmdate('c');
            if ($reset) $this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_n11'",[$company]);
            // Each completed API page is committed once with its cursor under the same lock.
            if (($page['type']??'')==='returns'){
                $buyers=$this->buyers??new ReturnBuyerIdentity(new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
                foreach($page['items'] as &$claim){
                    $claim=$buyers->protectN11($company,$claim);
                    if(isset($claim['buyerIdentity']))$page['buyerIdentities'][(string)$claim['claimReturnId']]=$claim['buyerIdentity'];
                    unset($claim['buyerIdentity']);
                }
                unset($claim);
            }
            if ($page) $this->save($company,'stage_n11',$page);
            if((new OrderImportQueue($this->db))->holdSnapshot($company,'n11',$state,$complete))$complete=false;
            if ($complete) {
                unset($state['financialSnapshotHeld']);
                $packages=[];$claims=[];$identities=[];
                foreach ($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='stage_n11' ORDER BY id",[$company]) as $row) {
                    $data=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                    if ($data['type']==='orders') array_push($packages,...$data['items']);else array_push($claims,...$data['items']);
                    $identities=array_replace($identities,$data['buyerIdentities']??[]);
                }
                $orders=N11SalesMapper::orders($packages,$claims,$state['scan']['from'],$state['scan']['through']);
                ReconciliationReference::complete($state,$orders,$state['scan']['from'],$state['scan']['through']);
                $this->publish($company,$orders,$state['scan']['from'],$state['scan']['through']);
                $requests=N11ReturnIdentity::requests($claims,$packages);
                foreach($requests as &$request)if(isset($identities[$request['id']]))$request['buyerIdentity']=$identities[$request['id']];
                unset($request);
                ReturnRequestSnapshot::publish($state,$requests,$state['scan']['from'],$state['scan']['through']);
                $state['startDate']=$state['scan']['from'];$state['watermark']=$state['scan']['through'];
                $state['lastSuccessAt']=gmdate('c');$state['phase']='ready';$state['salesPublished']=true;$state['sampleOnly']=false;
                $state['snapshotRevision']=$config['revision'];unset($state['scan'],$state['failure'],$state['preview']);
                IntegrationImportEvidence::completed($state,$config,true);
                $this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_n11'",[$company]);
            }
            $this->save($company,'sync_n11',$state,$current['id']??null);
        });
    }
    private function publish(int $company,array $orders,string $from,string $through): void
    {
        $months=[];
        foreach(SalesCalendar::months($from,$through) as $month){
            $months[$month]=['channel'=>'n11','month'=>$month,...SalesCalendar::ZERO,'integration'=>'n11',
                'source'=>'n11 API · Mağaza fatura tutarı · İptal ve onaylı iadeler düşülür · Komisyon düşülmemiştir',
                'dailySales'=>['from'=>$from,'through'=>$through,'days'=>[]]];
        }
        foreach ($orders as $order) {
            if (!isset($months[$order['month']])) throw new SyncFailure(422);
            SalesCalendar::add($months[$order['month']],$order);$day=SalesCalendar::day($order['createdAt']);
            $months[$order['month']]['dailySales']['days'][$day]??=SalesCalendar::ZERO;
            SalesCalendar::add($months[$order['month']]['dailySales']['days'][$day],$order);
        }
        $existing=[];
        foreach ($this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row) {
            $sale=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if ($sale['channel']!=='n11'||!isset($months[$sale['month']])) continue;
            if (($sale['integration']??null)!=='n11'||isset($existing[$sale['month']])) throw new SyncFailure(409);
            $existing[$sale['month']]=$row['id'];
        }
        $this->db->run("DELETE FROM records WHERE company_id=? AND kind='order_n11'",[$company]);
        foreach ($orders as $order) $this->save($company,'order_n11',$order);
        foreach ($months as $month=>$summary) $this->save($company,'sales',$summary,$existing[$month]??null);
    }
    private function save(int $company,string $kind,array $value,?int $id=null): void
    {
        $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($id) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$id]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$payload,gmdate('c')]);
    }
}
