<?php
declare(strict_types=1);
namespace Omnigo;

/** Joins display metadata and exact quantities without changing source orders or financial totals. */
final class ReturnRequestEnrichment
{
    public function __construct(private Database $db,private ?OrderDetails $details=null){}
    public function apply(int $company,string $provider,array $items): array
    {
        if(!$items)return [];
        $orders=[];$details=$this->orderContext($company,$provider,$items);
        if($provider==='hepsiburada'&&RecentOrderSchema::available($this->db)){
            foreach($this->db->all("SELECT payload FROM recent_orders WHERE company_id=? AND provider='hepsiburada'",[$company]) as $row){$value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$orders[$value['externalId']]=$value;}
        }
        foreach($this->db->all('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'order_'.$provider]) as $row){$value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$orders[$value['externalId']]=$value;}
        foreach($items as &$item){
            $context=$details[(string)$item['orderNumber']]??[];
            $item['customerName']=$context['customerName']??$this->details?->returnBuyerName($company,$provider,$item);
            unset($item['buyerIdentity']); // Protected storage envelopes never leave the display API.
            $order=$orders[$item['orderNumber']]??null;
            if($provider==='idefix'&&$order&&($item['quantity']??null)===null){
                $item['quantity']=self::singleUnitQuantity($item,$order);
            }
            if($provider==='pazarama'&&$order&&($item['quantity']??null)===null&&in_array($item['status']??'', ['8','10'],true)){
                $line=(string)($item['lineId']??'');
                $matches=$line===''?[]:array_values(array_filter($order['products']??[],static fn($product)=>(string)($product['lineId']??'')===$line));
                if(count($matches)===1)$item['quantity']=self::completedQuantity(['products'=>$matches]);
            }
            if($order&&empty($item['product'])&&!empty($item['lineId'])){
                $field=$provider==='n11'?'productId':'lineId';$names=[];
                foreach($order['products']??[] as $product)if((string)($product[$field]??'')===(string)$item['lineId']&&!empty($product['title']))$names[]=$product['title'];
                $names=array_values(array_unique($names));if(count($names)===1)$item['product']=$names[0];
            }
            $item['skus']=$this->requestSkus($provider,$item,$order['products']??[],$context['lines']??[]);
            if($provider==='pazarama'&&$this->details){
                try{
                    $packages=$this->details->packages($company,'Pazarama',$item['orderNumber']);
                    $numbers=array_values(array_unique(array_filter(array_column($packages,'orderNumber'))));
                    if(count($numbers)===1){$item['externalId']=$item['orderNumber'];$item['orderNumber']=$numbers[0];}
                }catch(\Throwable){/* Keep the original source ID if the protected detail is unavailable. */}
            }
        }
        unset($item);return $items;
    }
    /** Quantities from completed order lines only; money is never divided to guess a partial quantity. */
    public static function completedQuantity(array $order): ?int
    {
        $total=0;$found=false;
        foreach($order['products']??[] as $product){
            if(($product['returnCents']??0)<=0)continue;
            $found=true;$quantity=$product['quantity']??null;$returned=$product['refundedQuantity']??null;
            if(!is_int($quantity)||$quantity<1)return null;
            if($returned===null&&($product['cancelCents']??null)===0){
                // A full line refund has an exact quantity, including legacy rows without netQuantity.
                if(($product['grossCents']??null)===$product['returnCents'])$returned=$quantity;
                elseif(is_int($product['netQuantity']??null))$returned=$quantity-$product['netQuantity'];
            }
            if(!is_int($returned)||$returned<1||$returned>$quantity)return null;
            $total+=$returned;
        }
        return $found?$total:null;
    }
    private static function singleUnitQuantity(array $item,array $order): ?int
    {
        $line=(string)($item['lineId']??'');if($line==='')return null;
        $matches=array_values(array_filter($order['products']??[],static fn($product)=>(string)($product['lineId']??'')===$line));
        // A repeated/multi-unit line cannot establish how many units were requested for return.
        return count($matches)===1&&($matches[0]['quantity']??null)===1?1:null;
    }
    private function requestSkus(string $provider,array $item,array $products,array $packageLines): array
    {
        if(!empty($item['skus']))return $item['skus'];
        $line=(string)($item['lineId']??'');
        if(($item['status']??'')==='recorded_return'&&$line===''){
            return ReturnRequestMapper::skus(array_filter($products,static fn($product)=>($product['returnCents']??0)>0));
        }
        if($line==='')return [];
        $matches=array_filter([...$products,...$packageLines],static function(array $product)use($provider,$line): bool {
            $identity=$provider==='n11'?($product['productId']??$product['sourceProduct']['listingId']??''):($product['lineId']??'');
            return (string)$identity===$line;
        });
        $skus=ReturnRequestMapper::skus($matches);
        return count($skus)===1?$skus:[];
    }
    private function orderContext(int $company,string $provider,array $items): array
    {
        if(!$this->details)return [];
        $result=[];$numbers=array_values(array_unique(array_map(static fn($item)=>(string)$item['orderNumber'],$items)));
        foreach(array_chunk($numbers,100) as $batch){
            $orders=array_map(static fn($number)=>['provider'=>ReturnRequestList::PROVIDERS[$provider],'externalId'=>$number],$batch);
            // Use the protected order details; never substitute the recipient or another firm's name.
            foreach($this->details->summaries($company,$orders,includeReturnLines:true) as $order){
                $names=array_values(array_unique(array_filter(array_map('trim',$order['customerNames']??[]))));
                $result[$order['externalId']]=['customerName'=>$names?implode(' / ',$names):null,'lines'=>$order['returnLines']];
            }
        }
        return $result;
    }
}
