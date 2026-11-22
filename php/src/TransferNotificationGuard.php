<?php
declare(strict_types=1);
namespace Omnigo;

/** A source delivery enters each destination once. Replayed statuses are not new imports. */
final class TransferNotificationGuard
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function filter(int $company,int $record,array $detail): array
    {
        $events=$detail['events']??[];$transfers=array_intersect($events,['delivery_added','shipment_added']);
        if(!$transfers||$record<1)return $detail;
        $source=$this->db->one('SELECT provider,external_id FROM delivery_orders WHERE company_id=? AND id=?',[$company,$record]);
        if(!$source)return $detail;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';$legacy=null;
        foreach($transfers as $event){
            $key=hash('sha256',json_encode([$source['provider'],$source['external_id'],$event],JSON_THROW_ON_ERROR));
            $known=$this->db->one("SELECT id FROM order_notifications WHERE company_id=? AND provider='_transfer' AND order_key=?",[$company,$key]);
            if(!$known){
                if($legacy===null){$legacy=[];foreach($this->db->all("SELECT id,after_value FROM audit WHERE company_id=? AND record_id=? AND action='operations.mail' ORDER BY id",[$company,$record]) as $row){$prior=$this->cipher->open($company,'operation-mail:'.$row['id'],$row['after_value']);$legacy=array_merge($legacy,$prior['events']??[]);}}
                $known=in_array($event,$legacy,true);
                $added=$this->db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,'checkpoint','{}',?)",[$company,'_transfer',$key,gmdate('c')]);
                if($added->rowCount()!==1)$known=true;
            }
            if($known)$events=array_values(array_filter($events,fn($value)=>$value!==$event));
        }
        return [...$detail,'events'=>$events];
    }
}
