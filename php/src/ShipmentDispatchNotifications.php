<?php
declare(strict_types=1);
namespace Omnigo;

/** Summarizes only the successfully changed rows in the caller's transaction. */
final class ShipmentDispatchNotifications
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function record(array $user,int $company,array $rows): void
    {
        $groups=[];
        foreach($rows as $row){
            if(ShipmentStatus::resolve($row)==='shipped')continue;
            $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
            $city=ActivityNotification::clean($payload['province']??'');if($city==='')$city='Şehir bilgisi olmayan kayıtlar';
            $source=$payload['source']??[];
            $identity=($source['orderNumber']??$payload['orderNumber']??'');
            if($identity===''){
                $identity=isset($payload['sourceDeliveryId'])?'delivery:'.$payload['sourceDeliveryId']:'manual:'.$row['id'];
            }
            $groups[$city]['orders'][$row['provider'].':'.$identity]=true;
            $groups[$city]['pieces']=($groups[$city]['pieces']??0)+max(0,(int)($payload['quantity']??0));
            $groups[$city]['rows'][]=(int)$row['id'];
        }
        foreach($groups as $city=>$group)(new OperationalMailEvents($this->db,$this->cipher))->record($company,0,['events'=>['dispatched'],'provider'=>'portal','section'=>'shipments','city'=>$city,'orderCount'=>count($group['orders']),'pieceCount'=>$group['pieces'],'rowIds'=>$group['rows'],'actorName'=>ClientSessionOperator::actorName($user)],(int)$user['id']);
    }
}
