<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only operational projection. Source snapshots and financial records stay intact. */
final class DeliveryFulfillment
{
    private array $entries=[];
    private array $returns=[];
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public static function key(array $order,array $product): ?string
    {
        $line=(string)($product['lineId']??'');
        $identity=$order['provider']==='Pazarama'?$order['externalId']:$order['orderNumber'];
        if($line===''||$identity==='')return null;
        return hash('sha256',$order['provider'].':'.hash('sha256',json_encode([$identity,$line],JSON_THROW_ON_ERROR)));
    }
    public function prime(int $company,array $orders): void
    {
        $keys=[];
        foreach($orders as $order)foreach($order['details']['products']??[] as $product){
            $key=self::key($order,$product);if($key!==null&&!array_key_exists($company.':'.$key,$this->entries))$keys[$key]=true;
        }
        foreach(array_chunk(array_keys($keys),200) as $batch){
            foreach($batch as $key)$this->entries[$company.':'.$key]=null;
            foreach($this->shipmentRows($company,$batch) as $row){
                unset($payload);
                $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
                unset($row['payload']);$row['approvedReturn']=$payload['marketplaceReturn']??null;
                $row['check']=['id'=>(int)$row['id'],'version'=>(int)$row['version'],'provider'=>$row['provider'],'month'=>$row['month'],'city'=>$row['city'],
                    'customerName'=>$payload['customerName']??'','productName'=>$payload['productName']??'','rowStatus'=>ShipmentStatus::resolve($row),...ShipmentFulfillment::presentation($row,$payload)];
                $this->entries[$company.':'.$row['source_key']]=$row;
            }
        }
    }

    /** A historical shipment payload may contain a whole document; read one at a time. */
    private function shipmentRows(int $company,array $keys): \Generator
    {
        $pdo=$this->db->pdo;
        $buffered=$this->db->mysql()?$pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY):null;
        $statement=null;
        try{
            if($buffered!==null)$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $params=implode(',',array_fill(0,count($keys),'?'));
            $statement=$this->db->run("SELECT * FROM shipment_entries WHERE company_id=? AND source_key IN ($params)",[$company,...$keys]);
            while($row=$statement->fetch())yield $row;
        }finally{
            $statement?->closeCursor();
            if($buffered!==null)$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
        }
    }

    public function project(int $company,array $order,bool $forDocument=false,bool $forProductRows=false): array
    {
        $this->prime($company,[$order]);$groups=['pending'=>[],'shipped'=>[],'delivered'=>[],'review'=>[]];$versions=[];$actual=[];$checks=[];$reviewProducts=[];
        $planning=['pending'=>[],'shipped'=>[]];$labels=[];
        $index=$this->returns[$company.':'.$order['provider']]??=ApprovedReturnIndex::snapshot($this->db,$company,$order['provider']);
        $approved=$index->lines($order);$returnLines=[];
        $excluded=!empty($order['details']['superseded'])||!empty($order['operationalExcluded']);
        $products=$order['details']['products']??$order['products']??[];
        $occurrences=[];
        foreach($products as $product){
            if($forProductRows){
                $sourceId=(string)($product['lineId']??'');
                $identity=json_encode($sourceId!==''?['line',$sourceId]:['legacy',$product['sku']??'',$product['title']??''],JSON_THROW_ON_ERROR);
                $occurrence=$occurrences[$identity]??0;
                $occurrences[$identity]=$occurrence+1;
            }
            $cancelled=(int)($product['cancelledQuantity']??$order['cancellation']['lines'][$product['lineId']??'']['cancelledQuantity']??0);
            $key=self::key($order,$product);$row=$key===null?null:($this->entries[$company.':'.$key]??null);
            $returned=max((int)($product['refundedQuantity']??0),(int)($approved['lines'][(string)($product['lineId']??'')]['returnedQuantity']??0),$approved['issues']?(int)($row['approvedReturn']['returnedQuantity']??0):0);
            $quantity=max(0,(int)($product['quantity']??0)-$cancelled-$returned);
            if($returned>0)$returnLines[(string)$product['lineId']]=$returned;
            // Older Koçtaş snapshots contain the reduced dispatch quantity only in products.
            if($order['provider']==='Koçtaş'&&!array_key_exists('refundedQuantity',$product)){
                foreach($order['products']??[] as $dispatchProduct){
                    if(($dispatchProduct['sku']??'')===($product['sku']??'')&&($dispatchProduct['title']??'')===($product['title']??'')){
                        $quantity=min($quantity,(int)$dispatchProduct['quantity']);break;
                    }
                }
            }
            if($row&&$row['removed_at']===null)$checks[]=$row['check'];
            if($excluded||$quantity<1||($row&&$row['removed_at']!==null))continue;
            $source=DeliveryPlan::marketplaceState($order,$product,$row['check']['marketplaceStatus']??'');
            if(in_array($source,['cancelled','undelivered','returned'],true))continue;
            // Returns and unrecognised source states must not re-enter dispatch documents.
            if($source==='unknown'&&isset($product['status'])&&$product['status']!=='')continue;
            if(!$row&&!$order['active']&&!in_array($source,['shipped','delivered','undelivered','review'],true))continue;
            $status=!empty($row['check']['manualStatusOverride'])?ShipmentFulfillment::physical($row['check']['displayRowStatus']):(in_array($source,['pending','shipped','delivered','completed','cancelled'],true)?ShipmentFulfillment::physical($source):($row?($row['check']['fulfillmentStatus']??'pending'):'pending'));
            if(in_array($status,['cancelled','returned'],true))continue;
            $code=ShipmentProductCode::sourceCode($order['provider'],$product);
            $entry=['title'=>$product['title']??'','sku'=>$product['sku']??'','quantity'=>$quantity];
            if($code!=='')$entry['productCode']=$code;
            $groups[$status][]=$entry;$actual[]=$status;
            if($order['active']&&isset($planning[$source])){
                $planned=$entry;
                if($forProductRows){
                    $planned['rowKey']=hash('sha256',json_encode([$company,$order['id'],$identity,$occurrence],JSON_THROW_ON_ERROR));
                    $planned['sellerStockCode']=$code;
                }
                $planning[$source][]=$planned;
                if($forDocument)$labels[]=DeliveryShippingLabels::forProduct($order,$product);
            }
            if($row)$versions[(int)$row['id']]=(int)$row['version'];
        }
        $activeProducts=[...$groups['pending'],...$groups['shipped'],...$groups['review']];
        $status=count(array_unique($actual))===1?$actual[0]:($actual?'mixed':'cancelled');
        $value=[...$order,'marketplaceActive'=>$order['active'],'marketplaceStatusText'=>$order['deliveryStatus']??$order['status']??'',
            'active'=>(bool)$activeProducts,'products'=>$activeProducts,'fulfillmentProducts'=>$groups,
            'planningProducts'=>$planning,
            'deliveryStatus'=>$status==='mixed'?'Kısmi sevkiyat':ShipmentFulfillment::label($status),
            'fulfillmentStatus'=>$status,'fulfillmentVersions'=>$versions,'fulfillmentChecks'=>$checks,
            'approvedReturnLines'=>$returnLines,'returnIssues'=>$approved['issues'],
            'fulfillmentReviewProducts'=>$reviewProducts,'fulfillmentNeedsReview'=>(bool)$reviewProducts];
        // Delivered lines remain valid source data, but cannot be selected for a new dispatch.
        // Validate them when an order first arrives already delivered so ingestion is not blocked.
        $validation=DeliveryMapper::finish([...$value,'products'=>$activeProducts?:$groups['delivered']]);
        $validation['recipientName']=trim($order['details']['recipientName']??$order['recipientName']??'');
        if($validation['recipientName']==='')$validation['issues'][]='Alıcı adı eksik';
        if($forDocument)$validation['shippingLabels']=DeliveryShippingLabels::merge($labels);
        return [...$validation,'products'=>$activeProducts];
    }
}
