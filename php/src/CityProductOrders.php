<?php
declare(strict_types=1);
namespace Omnigo;

/** A marketplace order occupies one table row; each delivery retains its own products. */
final class CityProductOrders
{
    public static function page(callable $read,int $requestedPage,int $pageSize,bool $export=false,string $sort='default'): array
    {
        $index=$rows=[];
        $timezone=new \DateTimeZone('Europe/Istanbul');
        $utc=new \DateTimeZone('UTC');
        foreach($read() as [$contact,$products,$packageId]){
            $key=$contact['orderKey'];
            $label=match($sort){
                'default','city'=>self::textSortKey($contact['city']),
                'recipient'=>self::textSortKey($contact['recipientName']??''),
                default=>'',
            };
            $orderedAt=$contact['orderedAt'];
            if(!isset($index[$key])){
                $index[$key]=['day'=>'','label'=>$label,'instant'=>'','key'=>$key,'orderedAt'=>'','ids'=>[]];
            }
            if($orderedAt!==''&&$orderedAt!==$index[$key]['orderedAt']){
                try{$date=(new \DateTimeImmutable($orderedAt,$timezone))->setTimezone($timezone);}
                catch(\Exception){$date=null;}
                if($date!==null){
                    $instant=$date->setTimezone($utc)->format('Y-m-d H:i:s.u');
                    if($index[$key]['instant']===''||strcmp($instant,$index[$key]['instant'])<0){
                        $index[$key]['day']=$date->format('Y-m-d');
                        $index[$key]['instant']=$instant;
                        $index[$key]['orderedAt']=$orderedAt;
                    }
                }
            }
            // An order can have several destinations. Use its first alphabetical label across all packages.
            if(strcmp($label,$index[$key]['label'])<0)$index[$key]['label']=$label;
            if($export)self::addPackage($rows,$contact,$products);
            else $index[$key]['ids'][]=$packageId;
        }
        uasort($index,static function($a,$b)use($sort){
            $recent=strcmp($b['instant'],$a['instant']);
            $order=match($sort){
                'newest'=>$recent,
                'oldest'=>(($a['instant']==='')<=>($b['instant']===''))?:-$recent,
                'city','recipient'=>strcmp($a['label'],$b['label'])?:$recent,
                default=>strcmp($b['day'],$a['day'])?:strcmp($a['label'],$b['label'])?:$recent,
            };
            return $order?:strcmp($a['key'],$b['key']);
        });
        $total=count($index);
        if(!$export){
            $page=min($requestedPage,intdiv(max(0,$total-1),$pageSize));
            $index=array_slice($index,$page*$pageSize,$pageSize,true);
            $ids=array_merge(...array_values(array_column($index,'ids')));
            foreach($read($ids) as [$contact,$products])self::addPackage($rows,$contact,$products);
        }
        $orders=[];
        foreach($index as $key=>$info){
            $rows[$key]['orderedAt']=$info['orderedAt'];
            $orders[]=$rows[$key];
        }
        foreach($orders as &$order){
            $order['deliveries']=array_values($order['deliveries']);
            foreach($order['deliveries'] as &$delivery)$delivery['products']=array_values($delivery['products']);
            unset($delivery);
        }
        unset($order);
        return [$total,$orders];
    }

    private static function textSortKey(string $value): string
    {
        $value=trim($value);
        if($value==='')return "\xff";
        static $collator;
        if($collator===null){
            $collator=class_exists(\Collator::class)?(\Collator::create('tr_TR')??false):false;
            if($collator)$collator->setStrength(\Collator::PRIMARY);
        }
        if($collator&&($key=$collator->getSortKey($value))!==false)return $key;
        // The Turkish alphabet is still ordered correctly on hosts without the ICU extension.
        $value=strtr($value,["I\u{0307}"=>'İ','I'=>'ı','İ'=>'i']);
        $value=strtr(mb_strtolower($value,'UTF-8'),[
            "c\u{0327}"=>'ç',"g\u{0306}"=>'ğ',"o\u{0308}"=>'ö',"s\u{0327}"=>'ş',"u\u{0308}"=>'ü',
            'â'=>'a','î'=>'i','û'=>'u',
        ]);
        return strtr($value,[
            'ç'=>'c~','ğ'=>'g~','ı'=>'i','i'=>'i~','ö'=>'o~','ş'=>'s~','ü'=>'u~',
        ]);
    }

    private static function addPackage(array &$rows,array $contact,array $products): void
    {
        $key=$contact['orderKey'];
        $rows[$key]??=['id'=>$key,'orderKey'=>$key,'orderedAt'=>$contact['orderedAt'],'quantity'=>0,'deliveries'=>[]];
        $order=&$rows[$key];
        if(isset($contact['cancellationMark']))$order['cancellationMark']=$contact['cancellationMark'];
        unset($contact['orderKey'],$contact['orderedAt'],$contact['cancellationMark']);
        $deliveryId=self::key([$order['id'],$contact]);
        $order['deliveries'][$deliveryId]??=['id'=>$deliveryId,...$contact,'products'=>[]];
        $items=&$order['deliveries'][$deliveryId]['products'];
        foreach($products as $product){
            $sku=$product['sellerStockCode'];
            $source=$product['sourceProduct']??[];
            // Conflicting marketplace variants must remain distinguishable even with a reused seller SKU.
            $identity=($sku!=='' || $source)
                ? [$sku,$product['sku'],$product['title'],$source]
                : ['line',$product['rowKey']];
            $id=self::key([$deliveryId,$identity]);
            $items[$id]??=['id'=>$id,'sku'=>$sku,'productName'=>$product['title'],'quantity'=>0];
            $items[$id]['quantity']+=$product['quantity'];
            $order['quantity']+=$product['quantity'];
        }
    }

    private static function key(array $parts): string
    {
        return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
    }
}
