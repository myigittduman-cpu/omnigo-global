<?php
declare(strict_types=1);
namespace Omnigo;

/** Operational quantities only: never changes sales, cancellations or payment totals. */
final class ApprovedReturnIndex
{
    private array $orders=[];
    private array $conflicts=[];
    public function __construct(private string $provider,array $claims,private bool $available=true)
    {
        $seen=[];$conflicts=[];
        foreach($claims as $claim){
            $id=(string)($claim['id']??'');if($id==='')continue;
            if(isset($seen[$id])&&$seen[$id]!==$claim)$conflicts[$id]=true;
            $seen[$id]=$claim;
        }
        foreach($seen as $id=>$claim){
            $order=(string)($claim['orderNumber']??'');
            if(isset($conflicts[$id]))$this->conflicts[$order]=true;
            elseif(self::approved($provider,$claim))$this->orders[$order][]=$claim;
        }
    }
    public static function approved(string $provider,array $claim): bool
    {
        $status=(string)($claim['status']??'');
        return match($provider){
            'Hepsiburada'=>!HepsiburadaClaims::isInvoice($claim)&&in_array($status,['Accepted','Refunded'],true)&&!($status==='Refunded'&&($claim['claimType']??'')==='UndeliveredProduct'),
            'n11'=>in_array($status,['APPROVED','MANUAL_REFUND'],true),
            'idefix'=>$status==='approved',
            'Pazarama'=>in_array($status,['8','10'],true),
            'PTT AVM'=>in_array($status,['iade','gondericisine_teslim_edildi'],true),
            // CLOSED alone may also follow a rejected/non-compliant return.
            'Koçtaş'=>empty($claim['rejectionReason'])&&($claim['compliant']??null)!==false&&(in_array($status,['IN_PROGRESS','RECEIVED'],true)||($status==='CLOSED'&&($claim['compliant']??null)===true)),
            default=>false,
        };
    }
    public function lines(array $order): array
    {
        $products=$order['details']['products']??[];$byId=[];$quantities=[];$problems=[];
        foreach($products as $product){$id=(string)($product['lineId']??'');if($id!=='')$byId[$id]=$product;}
        $identity=(string)($this->provider==='Pazarama'?$order['externalId']:$order['orderNumber']);
        if(!$this->available)$problems[]='Tamamlanmış iade taraması henüz yok.';
        if(isset($this->conflicts[$identity]))$problems[]='İade talebinde çelişkili kayıtlar var.';
        foreach($this->orders[$identity]??[] as $claim){
            $line=(string)($claim['orderLineId']??$claim['lineId']??'');
            if($this->provider==='n11'&&!isset($claim['orderLineId'])){
                $problems[]='İade talebinin ürün satırı kesin eşleştirilemedi.';continue;
            }
            // Another package of the same order may own this line.
            if(!isset($byId[$line]))continue;
            $quantity=$claim['quantity']??null;
            // idefix list API uses one physical unit per orderLineId. A multi-unit
            // source without an explicit return quantity must never be guessed.
            if($quantity===null&&in_array($this->provider,['idefix','Pazarama'],true)&&(int)$byId[$line]['quantity']===1)$quantity=1;
            if(!is_int($quantity)||$quantity<1){$problems[]='Onaylanan iadenin adedi doğrulanamadı.';continue;}
            $quantities[$line]=($quantities[$line]??0)+$quantity;
        }
        $result=[];
        foreach($byId as $id=>$product){
            $total=(int)($product['quantity']??0);$cancelled=(int)($product['cancelledQuantity']??0);
            $known=(int)($product['refundedQuantity']??0);
            if($this->provider==='Pazarama'&&in_array((string)($product['status']??''),['8','10'],true)){
                if($total===1)$known=1;
                elseif(!isset($quantities[$id]))$problems[]='Onaylanan iadenin adedi doğrulanamadı.';
            }
            $returned=max($known,$quantities[$id]??0);
            if($returned>$total||$cancelled>$total||$total<1){$problems[]='İade adedi sipariş adediyle uyuşmuyor.';continue;}
            if($returned<1)continue;
            // A cancelled/refunded unit is not removed a second time. The exact
            // return metadata remains distinct from the marketplace cancellation.
            $remaining=max(0,$total-$cancelled-$returned);
            $result[(string)$id]=['quantity'=>$total,'returnedQuantity'=>$returned,'remainingQuantity'=>$remaining,'partial'=>$remaining>0];
        }
        return ['lines'=>$result,'issues'=>array_values(array_unique($problems))];
    }
    public static function snapshot(Database $db,int $company,string $provider): self
    {
        $key=array_search($provider,ReturnRequestList::PROVIDERS,true);if($key===false)return new self($provider,[]);
        $row=$db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$key]);
        $state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        $source=$key==='hepsiburada'?($state['financial']??[]):$state;
        $claims=$source['returnRequests']['items']??[];
        if($key==='hepsiburada')foreach($claims as &$claim){
            $claim['claimType']??=$source['refunds'][$claim['id']]['claimType']??$state['fulfillmentClaims'][$claim['id']]['claimType']??null;
        }
        unset($claim);return new self($provider,$claims,isset($source['returnRequests'])||in_array($key,['pttavm','pazarama','koctas'],true));
    }
}
