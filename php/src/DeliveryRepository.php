<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryRepository
{
    private ?DeliveryFulfillment $fulfillment=null;
    public function __construct(private Database $db,private DeliveryCipher $cipher) {}
    /** Historical pages continue past a stale order; all of its local writes roll back together. */
    public function ingestIsolated(int $company,string $provider,array $value,bool $notify=true): bool
    {
        return (new OrderImportQueue($this->db))->attempt($company,$provider,$value['externalId'],fn()=>$this->ingest($company,$value,$notify));
    }
    /** Called in the marketplace transaction, under the company's lock. */
    public function ingest(int $company,array $value,bool $notify=true): void
    {
        $this->fulfillment=null;
        if($value['externalId']==='')throw new SyncFailure(422);
        if($value['provider']==='n11')N11DeliveryIdentity::migrateLegacy($this->db,$this->cipher,$company,$value['orderNumber'],$value['externalId']);
        $row=$this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND provider=? AND external_id=?',[$company,$value['provider'],$value['externalId']]);
        if(!$row&&!$value['active']&&!isset($value['details']))return;
        $old=$row?$this->decode($row):[];
        $previous=CancellationHistory::previousPackages($this->db,$this->cipher,$company,$value,$old);
        CancellationHistory::assertCurrent($value,$previous['old']);
        $value=DeliveryCorrections::apply($value,$value['manualCorrections']??$old['manualCorrections']??[]);
        $value=ShipmentSourceProduct::retain($value,$old);
        $profitabilityPlacedAt=$value['profitabilityPlacedAt']??null;
        unset($value['profitabilityPlacedAt']);
        (new ProfitabilityOrderCapture($this->db))->observe($company,$value,$profitabilityPlacedAt);
        $customerNotifications=$notify&&MarketplaceVisibility::visible($value['provider'])?new CustomerOrderNotifications($this->db,$company):null;
        $changes=$customerNotifications?new ShipmentImportChanges():null;
        $deliveryWasListed=$customerNotifications&&!empty($old['active'])&&empty($value['active'])&&!empty($value['cancellation']['cancelledQuantity'])
            &&$customerNotifications->enabled(CustomerOrderNotifications::DELIVERY_CANCELLED)
            &&$this->inCustomerDeliveryList($company,$old);
        $orderIdentity=$value['orderNumber']?:$value['externalId'];
        if(!empty($value['issues'])||!empty($value['manualCorrections']))foreach($value['shipmentItems']??[] as $item){
            $value['sourceShipmentStates'][$item['key']]=$item['payload']['marketplaceRowStatus']??$item['payload']['marketplaceShipping']??'unknown';
        }
        foreach($value['shipmentItems']??[] as $index=>$item){$value['shipmentItems'][$index]['payload']['sourceDeliveryId']=$value['externalId'];$value['shipmentItems'][$index]['payload']['orderNumber']=$value['orderNumber'];}
        $shipmentCount=0;
        $operational=null;
        if(isset($value['shipmentItems'])){
            $operational=(new DeliveryFulfillment($this->db,$this->cipher))->project($company,$value);
            $shipmentCount=(new ShipmentOrderIngestor($this->db,$this->cipher))->ingest($company,$value['provider'],$value['shipmentItems'],empty($value['issues']),$changes);unset($value['shipmentItems']);
        }
        $arrival=(new OrderNotifications($this->db))->observe($company,$value);
        $history=CancellationHistory::advance($value,$previous['old']);
        if($history['seen'])$value['cancellationHistory']=$history['seen'];
        $requests=CancellationRequestEvents::advance($value,$old);
        if($requests['seen'])$value['cancellationRequestHistory']=$requests['seen'];
        unset($value['dispatchDecision'],$value['deliveryListAdded']);
        if(!empty($old['deliveryListAdded'])&&$value['active']){
            $operational??=(new DeliveryFulfillment($this->db,$this->cipher))->project($company,$value);
            if($operational['planningProducts']['shipped'])$value['deliveryListAdded']=true;
        }
        $hash=hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $routingCity=$value['active']&&!empty($value['issues'])?'Diğer / Kontrol':$value['city'];
        if(($row['source_hash']??'')===$hash&&($row['city']??'')===$routingCity){
            if($shipmentCount&&$notify)(new OperationalMailEvents($this->db,$this->cipher))->record($company,(int)$row['id'],['events'=>['shipment_added'],'provider'=>$value['provider'],'orderNumber'=>$value['orderNumber'],'city'=>$value['city'],'shipmentCount'=>$shipmentCount]);
            if($changes)$customerNotifications->publish($value['provider'],$orderIdentity,$changes->events());
            return;
        }
        // Searchable lifecycle: 1 ready, 0 inactive, -1 cancelled. Payload active remains boolean.
        $lifecycle=$value['active']?1:(!empty($value['cancellation']['cancelledQuantity'])?-1:0);
        $payload=$this->cipher->seal($company,'order:'.$value['provider'].':'.$value['externalId'],$value);
        if($row){
            $fields=array_flip(['buyerName','phone','address','city','products','orderNumber']);
            $documentId=$old['active']&&$value['active']&&array_intersect_key($old,$fields)===array_intersect_key($value,$fields)?$row['document_id']:null;
            $this->db->run('UPDATE delivery_orders SET city=?,active=?,payload=?,source_hash=?,version=version+1,document_id=?,updated=? WHERE id=?',[$routingCity,$lifecycle,$payload,$hash,$documentId,gmdate('c'),$row['id']]);
        }
        else $this->db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,$value['provider'],$value['externalId'],$routingCity,$lifecycle,$payload,$hash,gmdate('c')]);
        $id=$row?(int)$row['id']:$this->db->insertId();$events=[];
        if(($value['active']||!empty($old['active']))&&(!$row||DeliveryPlan::sourceRevision($old)!==DeliveryPlan::sourceRevision($value))){
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(NULL,?,?,?,?)',
                [$company,'delivery.marketplace_status',$id,gmdate('c')]);
        }elseif(ShipmentSourceProduct::references($old)!==ShipmentSourceProduct::references($value)){
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(NULL,?,?,?,?)',
                [$company,'delivery.invoice_product_source',$id,gmdate('c')]);
        }
        if($arrival)$events[]='new_order';
        if($value['active']&&empty($old['active'])&&($arrival||$row||$shipmentCount)&&CustomerModules::enabled($this->db,$company,'deliveries'))$events[]='delivery_added';
        if($shipmentCount)$events[]='shipment_added';
        // A repeated API page cannot send the same cancellation again.
        $cancelled=$history['delta'];
        if($cancelled&&($previous['known']||$arrival))$events[]='cancelled';
        if($requests['new']&&($row||$arrival))$events[]='cancellation_requested';
        if($events&&$notify)(new OperationalMailEvents($this->db,$this->cipher))->record($company,$id,['events'=>$events,'provider'=>$value['provider'],'orderNumber'=>$value['orderNumber'],'city'=>$value['city'],'shipmentCount'=>$shipmentCount,'cancelledQuantity'=>$cancelled]);
        if($changes){
            $customerEvents=$changes->events();
            if(in_array('delivery_added',$events,true)&&$customerNotifications->enabled(CustomerOrderNotifications::DELIVERY_ADDED)
                &&$this->inCustomerDeliveryList($company,$value))$customerEvents[]=CustomerOrderNotifications::DELIVERY_ADDED;
            if($deliveryWasListed&&$lifecycle===-1&&$cancelled>0)$customerEvents[]=CustomerOrderNotifications::DELIVERY_CANCELLED;
            $customerNotifications->publish($value['provider'],$orderIdentity,$customerEvents);
        }
    }
    private function inCustomerDeliveryList(int $company,array $value): bool
    {
        if(!MarketplaceVisibility::visible($value['provider']))return false;
        $order=(new DeliveryFulfillment($this->db,$this->cipher))->project($company,$value);
        return (bool)DeliveryPlan::products($order)&&in_array($order['city'],[...CustomerCities::read($this->db,$company),'Diğer / Kontrol'],true);
    }
    public function decode(array $row): array
    {
        return [...$this->cipher->open((int)$row['company_id'],'order:'.$row['provider'].':'.$row['external_id'],$row['payload']),
            'id'=>(int)$row['id'],'version'=>(int)$row['version'],'documentId'=>$row['document_id']?(int)$row['document_id']:null,'updated'=>$row['updated']];
    }
    public function hepsiburadaShippingLabels(int $company,string $merchant,array $state): HepsiburadaShippingLabelCache
    {
        return new HepsiburadaShippingLabelCache($company,$merchant,$this->cipher,$state['packageLabels']??[]);
    }
    public function operationalBatch(array $rows,bool $forDocument=false,bool $forProductRows=false): array
    {
        if(!$rows)return [];
        $company=(int)$rows[0]['company_id'];
        foreach($rows as $row)if((int)$row['company_id']!==$company)throw new \LogicException('Firma kapsamı uyuşmuyor.');
        $orders=array_map(function($row){$order=$this->decode($row);if((int)$row['active']===-1)$order['operationalExcluded']=true;return $order;},$rows);
        // A product scan only consumes each batch once. Release its shipment cache after that batch.
        $projection=$forProductRows?new DeliveryFulfillment($this->db,$this->cipher):($this->fulfillment??=new DeliveryFulfillment($this->db,$this->cipher));
        $projection->prime($company,$orders);
        return array_map(fn($order)=>$projection->project($company,$order,$forDocument,$forProductRows),$orders);
    }
    public function operational(array $row,bool $forDocument=false): array{$this->fulfillment=null;return $this->operationalBatch([$row],$forDocument)[0];}
    public function listing(int $company,string $city,string $provider,string $mode,int $page,int $limit=50,bool $visibleOnly=false,array $filterValues=[]): array
    {
        if(!$this->db->pdo->inTransaction())return $this->db->transaction(fn()=>$this->listing($company,$city,$provider,$mode,$page,$limit,$visibleOnly,$filterValues));
        $this->fulfillment=null;
        $filterValues=[...$filterValues,...DeliveryFilters::storeScope($this->db,$company,$visibleOnly)];
        $selectedCities=array_values(array_diff($filterValues['allowedCities'],['Diğer / Kontrol']));
        if(!in_array($city,['all',...DeliveryMapper::CITIES,'Diğer / Kontrol'],true)||!in_array($provider,['all',...Repository::CHANNELS],true)||!in_array($mode,['pending','prepared','all','delayed','shipped','cancelled','delivered','review',DeliveryPlan::ADDITIONS],true)||$page<0||$page>2000)Http::fail(400,'Teslimat filtresini kontrol edin.');
        if(!in_array($limit,[10,20,50,250],true)&&!($limit===0&&$mode==='all'&&$page===0))Http::fail(400,'Liste boyutu geçersiz.');
        if($visibleOnly)MarketplaceVisibility::requireVisible($provider);
        if($mode===DeliveryPlan::ADDITIONS)return ['items'=>[],'total'=>0,'page'=>$page,'hasMore'=>false,'revision'=>ShipmentRevision::read($this->db,$company)];
        $isQueue=in_array($mode,['delayed','shipped','cancelled','delivered','review'],true);
        $delivered=new DeliveredShipmentQueue($this->db,$this->cipher);
        $queue=new DeliveryQueue($this->db,$this);
        $operational=(new DeliveryOperationalList($this->db,$this))->read($company,$city,$provider,$isQueue?'all':$mode,$page,$limit,$visibleOnly,$filterValues);
        $counts=$operational['cityCounts'];$parts=$operational['cityProducts'];
        unset($operational['cityCounts'],$operational['cityProducts']);
        if($mode==='delivered')$result=$delivered->listing($company,$city,$provider,$page,$limit,$visibleOnly,$filterValues);
        elseif($isQueue)$result=$queue->listing($company,$city,$provider,$mode,$page,$limit,null,$visibleOnly,$filterValues);
        else $result=$operational;
        $sync=[];foreach(['koctas','n11','pazarama','idefix','hepsiburada','pttavm','trendyol'] as $key){$r=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$key]);$s=$r?json_decode($r['payload'],true):[];$sync[$key]=['lastSuccessAt'=>$s['recentOrders']['lastPageAt']??$s['recentOrders']['lastSuccessAt']??$s['lastSuccessAt']??$s['deliveryLastPageAt']??null,'startDate'=>$s['startDate']??null];}
        return ['shippingScope'=>CustomerShippingScope::read((new CustomerManagement($this->db))->profile($company,true)),'revision'=>ShipmentRevision::read($this->db,$company),'cities'=>array_map(fn($name)=>['name'=>$name,'count'=>$counts[$name]??0,'productCount'=>$parts[$name]??0],['all',...$selectedCities,'Diğer / Kontrol']),
            ...$result,'page'=>$result['page']??$page,'sync'=>$sync,'queues'=>[...$queue->counts($company,$provider,$visibleOnly,null,$filterValues),'delivered'=>$delivered->count($company,$provider,$visibleOnly,$filterValues)]];
    }
}
