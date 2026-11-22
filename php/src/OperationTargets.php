<?php
declare(strict_types=1);
namespace Omnigo;

/** Derive identity from the tenant's real record, never from a browser-supplied order. */
final class OperationTargets
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public static function returnRef(array $row):string
    {
        return hash('sha256',json_encode([$row['providerKey'],$row['orderNumber'],$row['id'],$row['claimId']??'',$row['lineId']??''],JSON_THROW_ON_ERROR));
    }
    public function resolve(array $user,int $company,array $query):array
    {
        Security::admin($user);
        if(($query['kind']??'')==='shipment'){
            $id=filter_var($query['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id)Http::fail(400,'Sevkiyat kaydı seçin.');
            [$scope,$params]=CustomerCities::scope($this->db,$company,['company_id=? AND removed_at IS NULL AND '.MarketplaceVisibility::sql(),[$company]]);
            $row=$this->db->one('SELECT * FROM shipment_entries WHERE id=? AND '.$scope,[$id,...$params]);
            if(!$row)Http::fail(404,'Bu mağazada sevkiyat kaydı bulunamadı.');
            $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
            $provider=array_search($row['provider'],ReturnRequestList::PROVIDERS,true);
            if($provider===false)Http::fail(404,'Pazaryeri bulunamadı.');
            $order=trim((string)($payload['orderNumber']??$payload['sourceOrderNumber']??''));
            // Some imported rows use the external order identity in sourceOrderId.
            if($order==='')$order=trim((string)($payload['sourceOrderId']??''));
            $identity=$order!==''?'order:'.$order:'shipment:'.$id;
            $source=['kind'=>'shipment','id'=>$id,'provider'=>$provider,'month'=>$row['month']];
            $title=$payload['productName']??'Sevkiyat kaydı';
        }elseif(($query['kind']??'')==='return'){
            $provider=(string)($query['provider']??'');$ref=(string)($query['ref']??'');
            if(!isset(ReturnRequestList::PROVIDERS[$provider])||!preg_match('/^[a-f0-9]{64}$/D',$ref))Http::fail(400,'İade kaydını kontrol edin.');
            $all=(new ReturnRequestList($this->db,new OrderDetails($this->db,$this->cipher)))->listing($user,$company,['provider'=>$provider],true);
            $matches=array_values(array_filter($all['items'],fn($item)=>hash_equals(self::returnRef($item),$ref)));
            if(count($matches)!==1)Http::fail(404,'Bu mağazada iade kaydı bulunamadı.');
            $payload=$matches[0];$order=$payload['orderNumber'];$identity='order:'.$order;
            $source=['kind'=>'return','provider'=>$provider,'ref'=>$ref];$title=$payload['product']?:'İade kaydı';
        }else Http::fail(400,'Teslimat veya iade kaydı seçin.');
        return ['key'=>hash('sha256',$provider.':'.$identity),'provider'=>ReturnRequestList::PROVIDERS[$provider],'orderNumber'=>$order,'title'=>$title,'source'=>$source];
    }
}
