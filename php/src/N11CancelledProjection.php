<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only view of overlapping n11 cancellation packages, scoped to one company. */
final class N11CancelledProjection
{
    private array $parents=[],$owners=[],$records=[];

    public function add(array $order): void
    {
        if(($order['provider']??'')!=='n11'||!empty($order['details']['superseded']))return;
        $number=trim((string)($order['orderNumber']??''));
        $products=$order['cancelledProducts']??[];$lines=$order['cancellation']['lines']??[];
        if($number===''||!$products||!$lines||(int)($order['cancellation']['cancelledQuantity']??0)<1)return;
        $known=[];
        foreach($order['details']['products']??[] as $product){
            $lineId=trim((string)($product['lineId']??''));
            if($lineId!=='')$known[$lineId]=true;
        }
        $cancelled=[];
        foreach($products as $product){
            $lineId=trim((string)($product['lineId']??''));$count=(int)($product['quantity']??0);
            // Never infer identity from titles, SKUs, quantities or a missing line ID.
            if($lineId===''||!isset($known[$lineId],$lines[$lineId])||$count<1||$count!==(int)$lines[$lineId]['cancelledQuantity'])return;
            $cancelled[$lineId]=['title'=>($product['title']??'')?:'Ürün adı iletilmedi','sku'=>$product['sku']??'','quantity'=>$count];
        }
        $expected=array_filter($lines,fn($line)=>(int)($line['cancelledQuantity']??0)>0);
        if(count($expected)!==count($cancelled))return;
        foreach($lines as $line)if((int)$line['quantity']<(int)$line['cancelledQuantity']||(int)$line['cancelledQuantity']<0)return;

        $id=(int)$order['id'];$this->parents[$id]=$id;
        // Retain only line metadata; addresses, phone numbers and encrypted payloads are not indexed.
        $this->records[$id]=['products'=>$cancelled,'lines'=>$lines,'duplicateLines'=>count($products)>count($cancelled)];
        foreach($cancelled as $lineId=>$product){
            $key=json_encode([$number,(string)$lineId],JSON_THROW_ON_ERROR);
            if(isset($this->owners[$key])){
                $left=$this->root($id);$right=$this->root($this->owners[$key]);
                // Stable representative: latest inserted package. Disjoint packages remain separate.
                $this->parents[min($left,$right)]=max($left,$right);
            }else $this->owners[$key]=$id;
        }
    }

    public function result(): array
    {
        $groups=[];$result=[];
        // Latest package metadata takes priority; quantities never add across snapshots.
        krsort($this->records,SORT_NUMERIC);
        foreach($this->records as $id=>$record){
            $root=$this->root($id);$groups[$root]['members'][]=$id;
            foreach($record['products'] as $lineId=>$product){
                if(!isset($groups[$root]['products'][$lineId]))$groups[$root]['products'][$lineId]=$product;
                else $groups[$root]['products'][$lineId]['quantity']=max($groups[$root]['products'][$lineId]['quantity'],$product['quantity']);
            }
            foreach($record['lines'] as $lineId=>$line){
                $old=$groups[$root]['lines'][$lineId]??['quantity'=>0,'cancelledQuantity'=>0];
                $groups[$root]['lines'][$lineId]=['quantity'=>max((int)$old['quantity'],(int)$line['quantity']),
                    'cancelledQuantity'=>max((int)$old['cancelledQuantity'],(int)$line['cancelledQuantity'])];
            }
        }
        foreach($groups as $root=>$group){
            if(count($group['members'])===1&&!$this->records[$root]['duplicateLines'])continue;
            foreach($group['members'] as $id)$result[$id]=[];
            $result[$root]=['products'=>array_values($group['products']),
                'cancellation'=>[...OrderCancellation::combine($group['lines']),'lines'=>$group['lines']]];
        }
        return $result;
    }

    private function root(int $id): int
    {
        $root=$id;
        while($this->parents[$root]!==$root)$root=$this->parents[$root];
        while($this->parents[$id]!==$id){$next=$this->parents[$id];$this->parents[$id]=$root;$id=$next;}
        return $root;
    }
}
