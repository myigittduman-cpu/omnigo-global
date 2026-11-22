<?php
declare(strict_types=1);
namespace Omnigo;

/** Search the complete date/provider scope; plaintext customer data is never persisted. */
final class OrderSearch
{
    public function __construct(private Database $db,private ?OrderDetails $details){}
    public function page(int $company,string $source,string $where,array $args,string $timestamp,int $page,string $query,bool $management=false,string $province='all'):array
    {
        $search=new ShipmentSearch($query);$total=0;$selected=[];$tail=[];$offset=0;
        do{
            $rows=$this->db->all('SELECT id,kind,payload,updated FROM '.$source.' WHERE '.$where.' ORDER BY '.$timestamp.' DESC,id DESC LIMIT 100 OFFSET '.$offset,$args);
            $items=array_map(self::item(...),$rows);
            if($this->details)$items=$this->details->summaries($company,$items,false,$management);
            foreach($items as $item){
                $destinations=$item['destinations']??[];
                if($province!=='all'&&!in_array($province,array_column($destinations,'province'),true))continue;
                if(!$search->matches([
                    'customerName'=>implode(' ',$item['customerNames']??[]),
                    'recipientName'=>implode(' ',$item['recipientNames']??[]),
                    'phone'=>implode(' ',$item['phones']??[]),
                    'deliveryAddress'=>implode(' ',$item['deliveryAddresses']??[]),
                    'recipientEmail'=>implode(' ',array_column($destinations,'email')),
                    'province'=>implode(' ',array_column($destinations,'province')),
                    'district'=>implode(' ',array_column($destinations,'district')),
                    'productName'=>($item['externalId']??'').' '.implode(' ',$item['orderNumbers']??[]).' '.implode(' ',array_map(fn($p)=>($p['title']??'').' '.($p['sku']??''),$item['products']??[])),
                ]))continue;
                if($total%OrderList::PAGE_SIZE===0)$tail=[];$tail[]=$item;
                if($total>=OrderList::PAGE_SIZE*$page&&$total<OrderList::PAGE_SIZE*($page+1))$selected[]=$item;
                $total++;
            }
            $offset+=count($rows);
        }while(count($rows)===100);
        $last=max(0,(int)ceil($total/OrderList::PAGE_SIZE)-1);
        return ['items'=>$page>$last?$tail:$selected,'total'=>$total,'page'=>min($page,$last)];
    }
    public static function item(array $row):array
    {
        $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        return ['id'=>(int)$row['id'],'provider'=>OrderList::PROVIDERS[substr($row['kind'],6)],...array_intersect_key($value,array_flip(['externalId','createdAt','state','grossCents','cancelCents','returnCents','refundsVerified','products'])),'updatedAt'=>$row['updated']];
    }
}
