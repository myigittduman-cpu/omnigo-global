<?php
declare(strict_types=1);
namespace Omnigo;

/** Executes just the durable local command. Remote imports and their lease remain untouched. */
final class InventoryCommandCompletion
{
    public function __construct(private Database $db){}
    public function finish(int $company,string $request):array
    {
        $lease=$this->db->transaction(function()use($company){
            $row=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$row||!(int)$row['enabled'])return null;
            if((int)$row['lease_until']>time()&&!empty($row['lease_token']))return ['token'=>$row['lease_token'],'owned'=>false];
            $token=bin2hex(random_bytes(20));$this->db->run('UPDATE inventory_jobs SET lease_token=?,lease_until=? WHERE company_id=?',[$token,time()+15,$company]);
            return ['token'=>$token,'owned'=>true];
        });
        if($lease)try{(new InventoryCommands($this->db))->process($company,$lease['token'],$request);}
        finally{if($lease['owned'])$this->db->run('UPDATE inventory_jobs SET lease_token=NULL,lease_until=0,next_run=0 WHERE company_id=? AND lease_token=?',[$company,$lease['token']]);}
        $row=$this->db->one('SELECT state,result FROM inventory_commands WHERE company_id=? AND request_id=?',[$company,$request]);
        return ['requestId'=>$request,'state'=>$row['state']??'pending','result'=>isset($row['result'])?json_decode($row['result'],true,32,JSON_THROW_ON_ERROR):null];
    }
}
