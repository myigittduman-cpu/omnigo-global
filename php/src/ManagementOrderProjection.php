<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only, tenant-scoped projection of delivery and shipment evidence for the management order list. */
final class ManagementOrderProjection
{
    private array $shipmentLines=[];

    public function __construct(private Database $db,private DeliveryCipher $cipher){}

    public function prime(int $company,array $packages):void
    {
        $keys=[];
        foreach($packages as $package)foreach($package['details']['products']??[] as $product){
            $key=DeliveryFulfillment::key($package,$product);
            if($key!==null)$keys[$key]=true;
        }
        foreach(array_chunk(array_keys($keys),100) as $batch){
            $placeholders=implode(',',array_fill(0,count($batch),'?'));
            $stmt=$this->db->run("SELECT id,provider,source_key,row_status,cancelled,shipped,invoiced,payload FROM shipment_entries
                WHERE company_id=? AND removed_at IS NULL AND source_key IN ($placeholders)",[$company,...$batch]);
            while($row=$stmt->fetch()){
                $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
                $this->shipmentLines[$row['source_key']]=['amount'=>$payload['invoiceCents']??null,
                    'status'=>ShipmentColorPolicy::status($row,$payload)];
                if($row['provider']==='Pazarama'){
                    $line=&$this->shipmentLines[$row['source_key']];
                    $line['amount']=PazaramaShipmentInvoice::read($row,$payload);
                    $line['approvedReturn']=$payload['marketplaceReturn']??null;
                    $line['manualInvoice']=in_array('invoiceCents',$payload['source']['locked']??[],true);
                    $line['useSource']=$line['amount']===null&&!PazaramaShipmentInvoice::keepRecordedAmount($row,$payload)&&($payload['invoiceBasis']??null)!=='buyer_product';
                    unset($line);
                }
            }
            $stmt->closeCursor();
        }
    }

    public static function destinations(array $packages):array
    {
        $result=[];
        foreach($packages as $package){
            $contacts=[$package['details']??[]];
            if($package['provider']==='Trendyol'&&!empty($package['trendyolSourceItems'])){
                $contacts=array_column($package['trendyolSourceItems'],'base');
            }
            foreach($contacts as $contact){
                $city=trim((string)($contact['province']??''))?:($package['city']??'');
                $normalized=DeliveryMapper::city($city);
                $destination=[
                    'recipientName'=>trim((string)($contact['recipientName']??'')),
                    'email'=>(string)($contact['recipientEmail']??''),
                    'phone'=>trim((string)($contact['phone']??''))?:($package['phone']??''),
                    'deliveryAddress'=>trim((string)($contact['deliveryAddress']??''))?:($package['address']??''),
                    'province'=>$normalized==='Diğer / Kontrol'?$city:$normalized,
                    'district'=>trim((string)($contact['district']??''))?:($package['district']??''),
                    'invoiceType'=>(string)($contact['customerType']??''),
                ];
                if(array_filter($destination))$result[json_encode($destination,JSON_THROW_ON_ERROR)]=$destination;
            }
        }
        return array_values($result);
    }

    public function amountAndStatus(array $order,array $packages):array
    {
        $lines=[];$incomplete=false;$partialReturn=false;
        $pazarama=[];
        if(($order['provider']??'')==='Pazarama')foreach($order['products']??[] as $line){
            $id=(string)($line['lineId']??'');if($id==='')continue;
            $pazarama[$id]=array_key_exists($id,$pazarama)?null:$line;
        }
        foreach($packages as $package){
            foreach($package['details']['products']??[] as $product){
                $key=DeliveryFulfillment::key($package,$product);
                if($key===null){$incomplete=true;continue;}
                $quantity=$product['quantity']??null;
                $cancelled=(int)($product['cancelledQuantity']??0);
                $evidence=$this->shipmentLines[$key]??null;
                $source=$product['shippingState']??OrderShipping::state($order['provider'],$product['status']??'');
                if($source==='unknown')$source=$package['shippingState']??'';
                $status=$evidence['status']??(ShipmentSourceStatus::rowStatus(['marketplaceShipping'=>$source==='returned'?'undelivered':$source])??'');
                if(is_int($quantity)&&$quantity>0&&$cancelled>=$quantity)$status='cancelled';
                $amount=$evidence!==null?$evidence['amount']:($product['amountCents']??null);
                if($package['provider']==='Pazarama'&&is_int($quantity)){
                    $financial=$pazarama[(string)($product['lineId']??'')]??null;
                    $sameLine=$financial!==null&&($package['externalId']??null)===($order['externalId']??null)&&$quantity===($financial['quantity']??null);
                    $returned=PazaramaInvoiceAmount::returnedUnits($quantity,$product,$sameLine?$financial:null,$evidence['approvedReturn']??null);
                    $partiallyReturned=$returned!==null&&$returned>0&&$returned<$quantity;
                    if($evidence===null||!empty($evidence['useSource'])){
                        $amount=array_key_exists('invoiceCents',$product)?$product['invoiceCents']:
                            ($sameLine?PazaramaInvoiceAmount::fromPricing($financial['pricing']??null,$financial['grossCents']??null):null);
                    }
                    if($returned===$quantity&&$quantity>0)$status='cancelled';
                    if($partiallyReturned)$partialReturn=true;
                    if(empty($evidence['manualInvoice']))$amount=PazaramaInvoiceAmount::afterReturn($amount,$quantity,$returned);
                }
                if($cancelled>0&&$cancelled<$quantity)$amount=null;
                $line=['status'=>$status,'amount'=>$amount,'quantity'=>$quantity];
                if(isset($lines[$key])&&$lines[$key]!==$line)$incomplete=true;
                $lines[$key]=$line;
            }
        }
        $expected=array_sum(array_column($order['products']??[],'quantity'));
        $complete=$lines&&!$incomplete&&$expected>0&&$expected===array_sum(array_column($lines,'quantity'));
        $statuses=array_values(array_unique(array_column($lines,'status')));
        $status=$complete&&count($statuses)===1?$statuses[0]:'';
        $gross=$order['grossCents']??0;
        if(in_array(strtoupper($order['state']??''),['CANCELED','CANCELLED','REFUSED','RETURNED','REFUNDED'],true)
            ||($gross>0&&(($order['cancelCents']??0)>=$gross||(!$partialReturn&&($order['returnCents']??0)>=$gross))))$status='cancelled';
        if(!$lines&&$status==='')$status=ShipmentSourceStatus::rowStatus(['marketplaceShipping'=>OrderShipping::state($order['provider'],$order['state']??'')])??'';
        $billable=array_filter($lines,static fn($line)=>$line['status']!=='cancelled');
        $verified=$complete&&$billable&&$status!=='cancelled';
        foreach($billable as $line)if(!is_int($line['amount'])||$line['amount']<0)$verified=false;
        $amount=$verified?array_sum(array_column($billable,'amount')):null;
        if(!$packages&&$pazarama&&$status!=='cancelled')$amount=self::archivedInvoice($order,$pazarama);
        return ['invoiceCents'=>$amount,'displayRowStatus'=>$status];
    }

    /** Some older financial orders have no delivery package retained. */
    private static function archivedInvoice(array $order,array $lines): ?int
    {
        if(count($lines)!==count($order['products']))return null;
        $gross=0;$cancelled=0;$refunded=0;$paid=0;$hasBillable=false;
        foreach($lines as $line){
            if($line===null||!is_int($line['quantity']??null)||$line['quantity']<1||!is_int($line['grossCents']??null))return null;
            $quantity=$line['quantity'];$gross+=$line['grossCents'];
            $cancel=$line['cancelCents']??0;$refund=$line['returnCents']??0;
            if(!is_int($cancel)||!is_int($refund)||$cancel<0||$refund<0)return null;
            $cancelled+=$cancel;$refunded+=$refund;
            if($cancel>0){
                if($refund!==0||$cancel!==$line['grossCents']||($line['netQuantity']??null)!==0)return null;
                continue;
            }
            $returned=PazaramaInvoiceAmount::returnedUnits($quantity,[],$line,null);
            if($returned===null||($line['netQuantity']??($quantity-$returned))!==$quantity-$returned)return null;
            if($returned===$quantity)continue;
            $amount=PazaramaInvoiceAmount::fromPricing($line['pricing']??null,$line['grossCents']);
            if($amount===null)return null;
            $paid+=PazaramaInvoiceAmount::afterReturn($amount,$quantity,$returned);$hasBillable=true;
        }
        return $hasBillable&&$gross===($order['grossCents']??null)&&$refunded===($order['returnCents']??0)
            &&$cancelled===($order['cancelCents']??0)?$paid:null;
    }
}
