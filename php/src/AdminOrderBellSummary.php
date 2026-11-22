<?php
declare(strict_types=1);
namespace Omnigo;

/** Adds authenticated order information to the administrator's bell, after audience filtering and pagination. */
final class AdminOrderBellSummary
{
    public function __construct(private Database $db,private DeliveryCipher $cipher) {}

    public function collect(int $company,array $rows): array
    {
        $groups=[];$legacy=[];
        foreach($rows as $row){
            if($row['kind']!=='order'||!isset(OrderList::PROVIDERS[$row['provider']]))continue;
            $payload=json_decode($row['payload']??'{}',true);
            $ref=$payload['orderRef']??$row['order_key']??'';
            if(!is_string($ref)||!preg_match('/^[a-f0-9]{64}$/D',$ref))continue;
            $groups[$row['provider']][$ref][]=(int)$row['id'];
            if($row['provider']==='pazarama'&&!isset($payload['orderRef']))$legacy[$ref]=true;
        }
        if(!$groups)return [];
        $hasDelivery=FileCenterSchema::available($this->db,'delivery_orders');
        $orders=new OrderNotificationOrder($this->db,$this->cipher);
        $result=[];
        foreach($groups as $provider=>$notices){
            $packages=$hasDelivery?$this->packages($company,$provider,$notices,$provider==='pazarama'?$legacy:[]):[];
            $refs=[];foreach($notices as $key=>$_)$refs[$key]=$packages[$key]['ref']??$key;
            $sales=$orders->snapshots($company,$provider,array_unique(array_values($refs)));
            foreach($notices as $key=>$ids){
                $name=$packages[$key]['buyerName']??'';$amount=$sales[$refs[$key]]['amountCents']??null;
                $fields=[];
                if($name!=='')$fields['buyerName']=$name;
                if(is_int($amount))$fields['amountCents']=$amount;
                foreach($ids as $id)$result[$id]=$fields;
            }
        }
        return $result;
    }

    private function packages(int $company,string $provider,array $notices,array $legacy): array
    {
        $name=OrderList::PROVIDERS[$provider];
        $packaged=in_array($provider,['hepsiburada','n11','idefix'],true);
        $where='company_id=? AND provider=?';$args=[$company,$name];
        if($this->db->mysql()&&!$legacy){
            $id=$packaged?"SUBSTRING_INDEX(external_id,':',1)":'external_id';
            $params=implode(',',array_fill(0,count($notices),'?'));
            $where.=" AND SHA2($id,256) IN ($params)";$args=[...$args,...array_keys($notices)];
        }
        $query=$this->db->run("SELECT external_id,payload FROM delivery_orders WHERE $where ORDER BY id",$args);
        $matches=[];
        while($row=$query->fetch()){
            $id=$row['external_id'];$ref=hash('sha256',$packaged?explode(':',$id,2)[0]:$id);
            if(!$legacy&&!isset($notices[$ref]))continue;
            try{
                $value=$this->cipher->open($company,'order:'.$name.':'.$id,$row['payload']);
            }catch(\RuntimeException|\JsonException){continue;}
            if(!empty($value['details']['superseded']))continue;
            $display=PttavmIdentity::display([...$value,'provider'=>$name]);
            $buyer=trim((string)($display['details']['customerName']??$display['customerName']??$display['buyerName']??''));
            $keys=[$ref];
            if($legacy){
                $oldKey=hash('sha256',(string)($value['orderNumber']??''));
                if(isset($legacy[$oldKey]))$keys[]=$oldKey;
            }
            foreach($keys as $key)if(isset($notices[$key])&&empty($matches[$key]['buyerName']))$matches[$key]=['ref'=>$ref,'buyerName'=>$buyer];
        }
        return $matches;
    }
}
