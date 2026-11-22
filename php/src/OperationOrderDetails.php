<?php
declare(strict_types=1);
namespace Omnigo;

/** Authenticated display only: source products and claim amounts never rewrite accounting. */
final class OperationOrderDetails
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function enrich(int $company,int $record,array $detail):array
    {
        if(!array_intersect(['cancelled','cancellation_requested','delivery_added','shipment_added','return_updated'],$detail['events']??[]))return $detail;
        $provider=array_search($detail['provider']??'',ReturnRequestList::PROVIDERS,true)?:strtolower($detail['provider']??'');
        $label=ReturnRequestList::PROVIDERS[$provider]??null;if(!$label)return $detail;
        $packages=[];
        if($record>0){$row=$this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND provider=? AND id=?',[$company,$label,$record]);if($row){$order=(new DeliveryRepository($this->db,$this->cipher))->decode($row);$packages=[['externalId'=>$row['external_id'],'customerName'=>$order['buyerName']??'','products'=>$order['details']['products']??$order['products']??[]]];}}
        if(!$packages&&trim($detail['orderNumber']??'')!=='')$packages=(new OrderDetails($this->db,$this->cipher))->packages($company,$label,$detail['orderNumber']);
        $claim=null;$isReturn=in_array('return_updated',$detail['events'],true);
        if($isReturn){
            $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);$sync=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
            $state=$provider==='hepsiburada'?($sync['financial']??[]):$sync;
            foreach($state['returnRequests']['items']??[] as $item)if((string)($item['id']??'')===(string)($detail['claimId']??'')&&($item['orderNumber']??'')===($detail['orderNumber']??'')){$claim=$item;break;}
            $claim??=array_intersect_key($detail,array_flip(['lineId','quantity','amountCents','product']));
            if($provider==='n11'&&!empty($claim['lineId'])){
                $idSql=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.externalId'))":"JSON_EXTRACT(payload,'$.externalId')";
                $financial=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='order_n11' AND $idSql=?",[$company,$detail['orderNumber']??'']);
                foreach($financial?json_decode($financial['payload'],true,64,JSON_THROW_ON_ERROR)['products']??[]:[] as $product)if((string)($product['productId']??'')===(string)$claim['lineId']){$claim['lineId']=$product['lineId']??$claim['lineId'];break;}
            }
            $detail['quantity']=$claim['quantity']??$detail['quantity']??null;$detail['claimAmountCents']=$claim['amountCents']??null;
        }
        $products=[];$invoiceLinks=[];$lineCount=array_sum(array_map(fn($package)=>count($package['products']??[]),$packages));
        foreach($packages as $package){
            if(empty($detail['buyerName'])&&!empty($package['customerName']))$detail['buyerName']=$package['customerName'];
            foreach($package['products']??[] as $product){
                $key=(string)($product['lineId']??'');$key=$key!==''?'line:'.$key:'item:'.hash('sha256',json_encode([$product['sku']??'',$product['title']??'',$product['quantity']??null],JSON_THROW_ON_ERROR));
                if($isReturn&&!(empty($claim['lineId'])&&$lineCount===1)&&(!isset($claim['lineId'])||(string)$claim['lineId']===''||!in_array((string)$claim['lineId'],array_map('strval',[$product['lineId']??'',$product['productId']??'']),true)))continue;
                $amount=$product['amountCents']??$product['grossCents']??null;
                if($label==='Pazarama'){
                    $amount=$product['invoiceCents']??null;
                    if(!array_key_exists('invoiceCents',$product)&&!empty($package['externalId'])&&!empty($product['lineId'])&&is_int($product['quantity']??null)){
                        $invoiceLinks[$key][]=[$label,$package['externalId'],$product['lineId'],$product['quantity']];
                    }
                }
                $products[$key]=['title'=>$product['title']??'','sku'=>$product['sku']??'','quantity'=>$isReturn?($claim['quantity']??null):($product['quantity']??null),'invoiceCents'=>$amount];
            }
        }
        if($invoiceLinks){
            foreach((new ShipmentPaymentSnapshots($this->db))->read($company,$invoiceLinks) as $key=>$amounts){
                $products[$key]['invoiceCents']=$amounts['netProductCents']??null;
            }
        }
        if($isReturn&&!$products&&!empty($claim['product']))$products[]=['title'=>$claim['product'],'sku'=>'','quantity'=>$claim['quantity']??null,'invoiceCents'=>null];
        return [...$detail,'products'=>array_values($products)];
    }
}
