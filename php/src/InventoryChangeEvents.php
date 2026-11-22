<?php
declare(strict_types=1);
namespace Omnigo;

/** Database-only outbox. The existing inventory/push workers deliver after commit. */
final class InventoryChangeEvents
{
    public function __construct(private Database $db){}
    public function record(int $company,string $key,string $product,int $actor,array $detail):void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Stok bildirimi işlemle birlikte kaydedilmeli.');
        $this->db->run('INSERT INTO inventory_change_events(company_id,event_key,product_key,actor_id,payload,created) VALUES(?,?,?,?,?,?)',[$company,$key,$product,$actor,json_encode($detail,JSON_THROW_ON_ERROR),gmdate('c')]);
        $this->db->run('UPDATE inventory_jobs SET next_run=0 WHERE company_id=?',[$company]);
    }
    public function publish(int $company,DeliveryCipher $cipher):int
    {
        return TransactionRetry::run($this->db,function()use($company,$cipher){
            $count=0;
            foreach($this->db->all("SELECT * FROM inventory_change_events WHERE company_id=? AND state='pending' ORDER BY created,event_key LIMIT 30".$this->db->lock(),[$company]) as $event){
                $actor=$this->db->one('SELECT name FROM users WHERE id=?',[$event['actor_id']]);
                $detail=[...json_decode($event['payload'],true,32,JSON_THROW_ON_ERROR),'section'=>'inventory','audience'=>'admin','actorName'=>$actor['name']??'Kullanıcı','productKey'=>$event['product_key']];
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[$event['actor_id'],$company,'inventory.alert',0,'null',$event['created']]);
                $audit=$this->db->insertId();
                $this->db->run('UPDATE audit SET after_value=? WHERE id=?',[$cipher->seal($company,'operation-mail:'.$audit,$detail),$audit]);
                (new OperationalNotifications($this->db,$cipher))->publish($company,'operation',$audit,'portal',$detail);
                $this->db->run("UPDATE inventory_change_events SET state='sent' WHERE company_id=? AND event_key=?",[$company,$event['event_key']]);$count++;
            }
            return $count;
        });
    }
}
