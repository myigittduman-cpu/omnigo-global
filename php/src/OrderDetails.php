<?php
declare(strict_types=1);
namespace Omnigo;

final class OrderDetails
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function returnBuyerName(int $company,string $provider,array $item): ?string
    {
        return (new ReturnBuyerIdentity($this->cipher))->name($company,$provider,$item);
    }
    public function packages(int $company,string $provider,string $externalId): array
    {
        $where='external_id=?';$args=[$company,$provider,$externalId];
        if(in_array($provider,['n11','idefix','Hepsiburada'],true)){
            // An n11 order can have several packages and different delivery addresses.
            $where="(external_id=? OR external_id LIKE ? ESCAPE '!')";
            $args[]=strtr($externalId,['!'=>'!!','%'=>'!%','_'=>'!_']).':%';
        }
        $repo=new DeliveryRepository($this->db,$this->cipher);
        $rows=$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND provider=? AND '.$where.' ORDER BY id',$args);
        $values=array_map($repo->decode(...),$rows);
        return array_values(array_filter(array_map(static function($value){
            $value=PttavmIdentity::display($value);$details=$value['details']??[];
            if(isset($details['products']))$details['products']=array_map(
                static fn($line)=>array_diff_key($line,['sourceProduct'=>true]),$details['products']);
            return ['provider'=>$value['provider'],'orderNumber'=>$value['orderNumber'],'externalId'=>$value['externalId'],'updatedAt'=>$value['updated'],'complete'=>isset($value['details']),
                'customerName'=>$value['buyerName'],'phone'=>$value['phone'],'deliveryAddress'=>$value['address'],
                'province'=>$value['city'],'district'=>$value['district']??'',...$details,'cancellation'=>$value['cancellation']??[]];
        },$values),fn($item)=>empty($item['superseded'])));
    }
    /** At most one delivery read per provider for each 100-order search batch. */
    public function summaries(int $company,array $items,bool $includeSku=false,bool $management=false,bool $includeReturnLines=false):array
    {
        $groups=[];$decoded=[];$repo=new DeliveryRepository($this->db,$this->cipher);
        foreach($items as $item)$groups[$item['provider']][$item['externalId']]=true;
        foreach($groups as $provider=>$keys){
            $clauses=[];$args=[$company,$provider];
            foreach(array_keys($keys) as $key){
                $clauses[]='external_id=?';$args[]=$key;
                if(in_array($provider,['n11','idefix','Hepsiburada'],true)){$clauses[]="external_id LIKE ? ESCAPE '!'";$args[]=strtr((string)$key,['!'=>'!!','%'=>'!%','_'=>'!_']).':%';}
            }
            $rows=$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND provider=? AND ('.implode(' OR ',$clauses).') ORDER BY id',$args);
            foreach($rows as $row){$value=PttavmIdentity::display($repo->decode($row));if(empty($value['details']['superseded']))$decoded[$provider][]=$value;}
        }
        $projection=$management&&$includeSku?new ManagementOrderProjection($this->db,$this->cipher):null;
        if($projection)foreach($decoded as $packages)$projection->prime($company,$packages);
        $matched=[];
        $items=array_map(function($item)use($decoded,$includeSku,$management,$includeReturnLines,$projection,&$matched){
            $packages=array_values(array_filter($decoded[$item['provider']]??[],fn($p)=>$p['externalId']===$item['externalId']||(in_array($item['provider'],['n11','idefix','Hepsiburada'],true)&&str_starts_with($p['externalId'],$item['externalId'].':'))));
            if($includeSku)$matched[$item['id']]=OrderSkuLines::match($item['provider'],$item['products']??[],$packages);
            $values=static fn($field,$fallback='')=>array_values(array_unique(array_filter(array_map(fn($p)=>$p['details'][$field]??$p[$field]??$p[$fallback]??'',$packages))));
            $extra=$management?['destinations'=>ManagementOrderProjection::destinations($packages)]:[];
            if($includeReturnLines)$extra['returnLines']=array_merge(...array_map(static fn($package)=>$package['details']['products']??[],$packages));
            if($projection)$extra+=$projection->amountAndStatus($item,$packages);
            return [...$item,...$extra,'statusLabel'=>OrderDisplayStatus::label($item['provider'],$packages),'cancellation'=>OrderCancellation::packageSummary($packages),'operationalCancellation'=>OperationalCancellation::summary($packages),'customerNames'=>$values('customerName','buyerName'),'recipientNames'=>$values('recipientName'),'phones'=>$values('phone'),'cities'=>$values('province','city'),'deliveryAddresses'=>$values('deliveryAddress','address'),'orderNumbers'=>$values('orderNumber')];
        },$items);
        return $includeSku?OrderSkuLines::annotate($this->db,$company,$items,$matched):$items;
    }
    public function summary(int $company,array $order): array
    {
        $packages=$this->packages($company,$order['provider'],$order['externalId']);
        $values=static fn($field)=>array_values(array_unique(array_filter(array_column($packages,$field),fn($value)=>$value!=='')));
        return ['statusLabel'=>OrderDisplayStatus::label($order['provider'],$packages),'cancellation'=>OrderCancellation::packageSummary($packages),'operationalCancellation'=>OperationalCancellation::summary($packages),'customerNames'=>$values('customerName'),'recipientNames'=>$values('recipientName'),'phones'=>$values('phone'),'cities'=>$values('province'),'deliveryAddresses'=>$values('deliveryAddress'),'orderNumbers'=>$values('orderNumber')];
    }
    public function detail(array $user,int $company,int $id): array
    {
        (new CustomerPermissions($this->db,$company))->require($user,'orders');
        $row=$id<0&&RecentOrderSchema::available($this->db)?$this->db->one('SELECT * FROM recent_orders WHERE id=? AND company_id=?',[-$id,$company]):$this->db->one('SELECT * FROM records WHERE id=? AND company_id=?',[$id,$company]);
        if($row&&$id<0)$row['kind']='order_'.$row['provider'];
        $provider=$row?OrderList::PROVIDERS[substr($row['kind'],6)]??null:null;
        if(!$provider||!str_starts_with($row['kind'],'order_'))Http::fail(404,'Sipariş bulunamadı.');
        $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        $projection=new UndeliveredSalesReader($this->db,$this->cipher);
        if($projection->enabled($company))foreach($projection->orders($company,substr($row['kind'],6),[$row]) as $entry)$value=$entry['order'];
        $order=['id'=>$id,'provider'=>$provider,...array_intersect_key($value,array_flip(['externalId','createdAt','state','grossCents','cancelCents','returnCents','refundsVerified','products'])),'updatedAt'=>$row['updated']];
        $packages=$this->packages($company,$provider,$value['externalId']);
        $visibility=CustomerSensitiveVisibility::flags($this->db,$user,$company,'orders');
        $cancel=OrderCancellation::packageSummary($packages);
        $cancelled=in_array(strtoupper($order['state']??''),['CANCELED','CANCELLED','REFUSED'],true)||($cancel['quantity']>0&&$cancel['cancelledQuantity']===$cancel['quantity'])||(($order['grossCents']??0)>0&&($order['cancelCents']??0)>=$order['grossCents']);
        $returned=in_array(strtoupper($order['state']??''),['RETURNED','REFUNDED'],true)||(($order['grossCents']??0)>0&&($order['returnCents']??0)>=$order['grossCents']);
        if((!$visibility['cancellations']&&$cancelled)||(!$visibility['returns']&&$returned))Http::fail(403,'Bu sipariş durumunu görüntüleme yetkiniz yok.');
        if(!$visibility['cancellations']){
            $packages=array_values(array_filter($packages,static function(array $package):bool{
                $state=$package['cancellation']??[];
                return ($state['cancelledQuantity']??0)===0||!empty($state['partial']);
            }));
        }
        return CustomerSensitiveVisibility::financial($this->db,$user,$company,[...$order,'statusLabel'=>OrderDisplayStatus::label($provider,$packages),'returnAmountVerified'=>PttavmPricing::returnAmountVerified($order),'packages'=>$packages,'cancellation'=>OrderCancellation::packageSummary($packages),'operationalCancellation'=>OperationalCancellation::summary($packages)],page:'orders');
    }
}
