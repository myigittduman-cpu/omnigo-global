<?php
declare(strict_types=1);
namespace Omnigo;

/** Filter encrypted orders before pagination. Keyset batches keep memory bounded. */
final class DeliveryQueue
{
    private array $cancelledProjections=[];
    public function __construct(private Database $db,private DeliveryRepository $repository){}
    public function listing(int $company,string $city,string $provider,string $queue,int $page,int $limit,?int $now=null,bool $visibleOnly=false,array $filterValues=[]): array
    {
        $cancelled=$queue==='cancelled';
        $projection=$cancelled?$this->cancelledProjection($company,$provider,$visibleOnly):[];
        $now??=time();$where='company_id=?';$params=[$company];
        if($visibleOnly)$where.=' AND '.MarketplaceVisibility::sql();
        if($city!=='all'){$where.=' AND city=?';$params[]=$city;}
        if($provider!=='all'){$where.=' AND provider=?';$params[]=$provider;}
        $items=[];$matched=0;$cursor=PHP_INT_MAX;$filters=new DeliveryFilters($filterValues);
        do{
            $batch=$this->db->all('SELECT * FROM delivery_orders WHERE '.$where.' AND id<? ORDER BY id DESC LIMIT 250',[...$params,$cursor]);
            $orders=$this->repository->operationalBatch($batch);
            foreach($batch as $index=>$row){
                $cursor=(int)$row['id'];$order=$orders[$index];
                if(array_key_exists($cursor,$projection)){
                    if(!$projection[$cursor])continue;
                    $order['cancellation']=$projection[$cursor]['cancellation'];
                    $products=$projection[$cursor]['products'];
                }else $products=self::products($order,$queue,$now);
                $age=OrderShipping::ageSeconds($order,$now);
                if(!$products||!$filters->matches([...$order,'queueProducts'=>$products]))continue;
                if($matched++<$page*$limit)continue;
                if($cancelled&&($summary=OperationalCancellation::summary([$order]))!==null){
                    $order['operationalCancellation']=$summary;
                    $order['fulfillmentStatus']='cancelled';
                }
                unset($order['dispatchDecision'],$order['planningProducts'],$order['deliveryListAdded']);
                $items[]=[...$order,'queueProducts'=>$products,'queue'=>$queue,'ageDays'=>$age===null?null:(int)floor($age/86400)];
                if(count($items)>$limit)break 2;
            }
        }while(count($batch)===250);
        return ['items'=>array_slice($items,0,$limit),'hasMore'=>count($items)>$limit];
    }
    /** Exact order counts across every page, with the same filters as the queues. */
    public function counts(int $company,string $provider,bool $visibleOnly=false,?int $now=null,array $filterValues=[]): array
    {
        $projection=$this->cancelledProjection($company,$provider,$visibleOnly);
        $now??=time();$where='company_id=?';$params=[$company];
        if($visibleOnly)$where.=' AND '.MarketplaceVisibility::sql();
        if($provider!=='all'){$where.=' AND provider=?';$params[]=$provider;}
        $counts=['delayed'=>0,'shipped'=>0,'cancelled'=>0,'review'=>0];$cursor=PHP_INT_MAX;$filters=new DeliveryFilters($filterValues);
        do{
            $batch=$this->db->all('SELECT * FROM delivery_orders WHERE '.$where.' AND id<? ORDER BY id DESC LIMIT 250',[...$params,$cursor]);
            $orders=$this->repository->operationalBatch($batch);
            foreach($batch as $index=>$row){
                $cursor=(int)$row['id'];$order=$orders[$index];
                foreach($counts as $queue=>$count){
                    $products=$queue==='cancelled'&&array_key_exists($cursor,$projection)?($projection[$cursor]['products']??[]):self::products($order,$queue,$now);
                    if($products&&$filters->matches([...$order,'queueProducts'=>$products],false))$counts[$queue]++;
                }
            }
        }while(count($batch)===250);
        return $counts;
    }
    /** Build once per request, before city filtering and pagination, so list and count agree. */
    private function cancelledProjection(int $company,string $provider,bool $visibleOnly): array
    {
        if(!in_array($provider,['all','n11'],true))return [];
        $key=$company.':'.(int)$visibleOnly;
        if(isset($this->cancelledProjections[$key]))return $this->cancelledProjections[$key];
        $projection=new N11CancelledProjection();$cursor=PHP_INT_MAX;
        $where="company_id=? AND provider='n11' AND active IN (-1,1)".($visibleOnly?' AND '.MarketplaceVisibility::sql():'');
        do{
            $batch=$this->db->all('SELECT * FROM delivery_orders WHERE '.$where.' AND id<? ORDER BY id DESC LIMIT 250',[$company,$cursor]);
            foreach($batch as $row){$cursor=(int)$row['id'];$projection->add($this->repository->decode($row));}
        }while(count($batch)===250);
        return $this->cancelledProjections[$key]=$projection->result();
    }
    private static function products(array $order,string $queue,int $now): array
    {
        if(!empty($order['details']['superseded']))return [];
        if($queue==='cancelled')return self::cancelledProducts($order);
        if(!$order['active'])return [];
        $age=OrderShipping::ageSeconds($order,$now);
        if($queue==='delayed'&&($age===null||$age<=5*86400))return [];
        if($queue==='review')return $order['fulfillmentReviewProducts']??$order['fulfillmentProducts']['review']??[];
        $state=$queue==='shipped'?'shipped':'pending';
        return $order['fulfillmentProducts'][$state]??[];
    }
    private static function cancelledProducts(array $order): array
    {
        // Undelivered lines are operational cancellations, without changing financial cancellation data.
        $undelivered=OrderShipping::products($order,'undelivered');
        $quantity=(int)($order['cancellation']['cancelledQuantity']??0);
        if($quantity<1)return $undelivered;
        $products=$order['cancelledProducts']??[];
        if(!$products)foreach($order['details']['products']??[] as $product){
            $count=(int)($product['cancelledQuantity']??0);
            if($count>0)$products[]=[...$product,'quantity'=>$count];
        }
        if(!$products)$products=[['title'=>'Ürün adı iletilmedi','quantity'=>$quantity]];
        return [...array_values(array_map(fn($product)=>[
            'title'=>($product['title']??'')?:'Ürün adı iletilmedi','sku'=>$product['sku']??'','quantity'=>(int)$product['quantity'],
        ],array_filter($products,fn($product)=>(int)($product['quantity']??0)>0))),...$undelivered];
    }
}
