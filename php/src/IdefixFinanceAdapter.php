<?php
declare(strict_types=1);
namespace Omnigo;

/** A separate queued projection of the last complete order/claim snapshot. */
final class IdefixFinanceAdapter implements FixedCadenceReadAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault){}
    public function nextDelay(): int{return 60;}
    public static function schedule(Database $db,IntegrationVault $vault): void
    {
        foreach($db->all("SELECT DISTINCT company_id FROM records WHERE kind='sync_idefix'") as $row){
            $company=(int)$row['company_id'];if(empty($vault->get($company,'idefix')['enabled']))continue;
            (new SyncQueue($db))->enqueue($company,'idefix Hakediş');
            $db->run("UPDATE jobs SET state='pending',attempts=0,next_run=? WHERE company_id=? AND provider='idefix Hakediş' AND state='failed' AND next_run<=? AND lease_token IS NULL",[time(),$company,time()-1800]);
        }
    }
    public function syncReadOnly(int $company,int $deadline): void
    {
        if(empty($this->vault->get($company,'idefix')['enabled']))throw new SyncFailure(403);
        try{$this->db->transaction(function()use($company){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_idefix'",[$company]);$sync=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
            if(empty($sync['ordersPublished'])||empty($sync['refundsVerified'])||empty($sync['watermark'])||empty($sync['lastSuccessAt'])||time()-strtotime($sync['lastSuccessAt'])>1800)throw new SyncFailure(409,60);
            $orders=[];foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_idefix'",[$company]) as $record)$orders[]=json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR);
            if(count($orders)!==($sync['orderCount']??-1))throw new SyncFailure(409,60);
            $entries=IdefixFinanceMapper::entries($orders);$store=new FinanceStore($this->db);$before=$store->state($company,'idefix');
            $from=FinanceMapper::date($sync['startDate']);$to=FinanceMapper::date($sync['watermark']);
            $store->publish($company,'idefix',$entries,$from,$to);
            $store->writeState($company,'idefix',['phase'=>'ready','lastSuccessAt'=>gmdate('c'),'sourceAsOf'=>$sync['watermark'],'from'=>$from,'through'=>$to,'entryCount'=>count($entries),'basis'=>'order_earnings','payoutDatesAvailable'=>false,'bankTransferAvailable'=>false],$before);
        });}catch(SyncFailure $error){
            // A source snapshot still being filled must not defer recovery for 30 minutes.
            if($error->httpStatus===409)throw new SyncFailure(503,60);
            throw $error;
        }
    }
}
