<?php
declare(strict_types=1);
namespace Omnigo;

/** Non-PII order index. Recipient/billing details stay in the encrypted delivery repository. */
final class RecentOrderStore
{
    public function __construct(private Database $db,private DeliveryRepository $deliveries){}
    public function commit(int $company,string $provider,array $config,?array $before,array $state,array $items,IntegrationVault $vault): void
    {
        $this->db->transaction(function()use($company,$provider,$config,$before,$state,$items,$vault){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $current=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
            if(($current['version']??0)!==($before['version']??0)||($vault->get($company,$provider)['revision']??null)!==($config['revision']??null))throw new SyncFailure(409);
            $queue=new OrderImportQueue($this->db);
            foreach($items as $item)$queue->attempt($company,$provider,$item['delivery']['externalId'],function()use($company,$provider,$item){
                $this->deliveries->ingest($company,$item['delivery']);$this->save($company,$provider,$item['unit']);
            });
            $payload=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_'.$provider,$payload,gmdate('c')]);
        });
    }
    private function save(int $company,string $provider,array $unit): void
    {
        $external=(string)($provider==='n11'?$unit['orderNumber']:$unit['externalId']);
        $row=$this->db->one('SELECT * FROM recent_orders WHERE company_id=? AND provider=? AND external_id=?',[$company,$provider,$external]);
        $old=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $packages=$old['arrivalPackages']??[];
        $lineIds=array_column($unit['products']??[],'lineId');sort($lineIds);
        $key=(string)($unit['packageId']??($provider==='n11'?hash('sha256',json_encode($lineIds,JSON_THROW_ON_ERROR)):$external));$packages[$key]=$unit;
        if(count($packages)>1000)throw new SyncFailure(422);
        $order=RecentOrderSummary::combine($provider,array_values($packages));
        // A split-only package is not a new order. Keep it until replacement packages arrive.
        $order??=['externalId'=>$external,'createdAt'=>$unit['createdAt'],'orders'=>0,'products'=>[]];
        if($provider==='n11')$order['refundsVerified']=false;
        $payload=json_encode([...$order,'arrivalPackages'=>$packages],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if(($row['payload']??null)===$payload)return;
        if($row)$this->db->run('UPDATE recent_orders SET payload=?,updated=? WHERE id=?',[$payload,gmdate('c'),$row['id']]);
        else $this->db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,$provider,$external,$payload,gmdate('c')]);
    }
}
