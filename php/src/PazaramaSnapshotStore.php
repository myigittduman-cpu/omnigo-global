<?php
declare(strict_types=1);
namespace Omnigo;

final class PazaramaSnapshotStore
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?DeliveryRepository $deliveries=null) {}
    public function state(int $company): ?array
    {
        return $this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company]);
    }
    public function commit(int $company,?array $before,array $config,array $state,array $page,bool $reset,bool $complete,array $deliveryItems=[]): void
    {
        $this->db->transaction(function() use($company,$before,$config,$state,$page,$reset,$complete,$deliveryItems) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->state($company);$fresh=$this->vault->get($company,'pazarama');
            if (($current['version']??0)!==($before['version']??0)||($fresh['revision']??null)!==($config['revision']??null)) throw new SyncFailure(409);
            ReconciliationReference::page($state,$deliveryItems,$reset);
            foreach($deliveryItems as $item)if($this->deliveries&&!$this->deliveries->ingestIsolated($company,'pazarama',$item))$state['scan']['isolatedOrder']=true;
            if($deliveryItems)$state['deliveryLastPageAt']=gmdate('c');
            if ($reset) $this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_pazarama'",[$company]);
            // Each completed API page is committed once with its cursor under the same lock.
            if ($page) $this->save($company,'stage_pazarama',$page);
            // A complete return projection remains independent of an isolated delivery retry.
            if($complete)PazaramaReturnProjection::complete($state);
            if((new OrderImportQueue($this->db))->holdSnapshot($company,'pazarama',$state,$complete))$complete=false;
            if ($complete) {
                unset($state['financialSnapshotHeld']);
                $items=[];
                foreach ($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='stage_pazarama' ORDER BY id",[$company]) as $row) {
                    $data=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                    array_push($items,...$data);
                }
                $orders=PazaramaSalesMapper::unique($items,$state['scan']['from'],$state['scan']['through']);
                ReconciliationReference::complete($state,$orders,$state['scan']['from'],$state['scan']['through']);
                $this->publish($company,$orders,$state['scan']['from'],$state['scan']['through']);
                $state['startDate']=$state['scan']['from'];$state['watermark']=$state['scan']['through'];
                $state['lastSuccessAt']=gmdate('c');$state['phase']='ready';$state['salesPublished']=true;$state['sampleOnly']=false;
                $state['snapshotRevision']=$config['revision'];unset($state['scan'],$state['failure'],$state['preview']);
                IntegrationImportEvidence::completed($state,$config,true);
                $this->db->run("DELETE FROM records WHERE company_id=? AND kind='stage_pazarama'",[$company]);
            }
            $this->save($company,'sync_pazarama',$state,$current['id']??null);
        });
    }
    private function publish(int $company,array $orders,string $from,string $through): void
    {
        $months=[];
        foreach(SalesCalendar::months($from,$through) as $month){
            $months[$month]=['channel'=>'Pazarama','month'=>$month,...SalesCalendar::ZERO,'integration'=>'pazarama',
                'source'=>'Pazarama API · İndirim sonrası ürün tutarı · İptal ve iade durumları düşülür · Komisyon düşülmemiştir',
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
            if ($sale['channel']!=='Pazarama'||!isset($months[$sale['month']])) continue;
            if (($sale['integration']??null)!=='pazarama'||isset($existing[$sale['month']])) throw new SyncFailure(409);
            $existing[$sale['month']]=$row['id'];
        }
        $this->db->run("DELETE FROM records WHERE company_id=? AND kind='order_pazarama'",[$company]);
        foreach ($orders as $order) $this->save($company,'order_pazarama',$order);
        foreach ($months as $month=>$summary) $this->save($company,'sales',$summary,$existing[$month]??null);
    }
    private function save(int $company,string $kind,array $value,?int $id=null): void
    {
        $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($id) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$id]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$payload,gmdate('c')]);
    }
}
