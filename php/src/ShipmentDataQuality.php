<?php
declare(strict_types=1);
namespace Omnigo;

/** Exact source links only: never infer recipients from buyers or match by names/phones. */
final class ShipmentDataQuality
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function enrich(int $company,array $items,?array $records=null,bool $includeProductIdentity=false,bool $includePaymentDetails=false,bool $forExport=false,bool $includeShippingLabels=false):array
    {
        $sources=[];$payloads=[];$groups=[];$linked=[];$codes=[];
        $includeInvoiceIdentity=$records!==null&&!$forExport;
        foreach($records??$this->records($company,$items) as [$row,$payload]){
            $payloads[$row['id']]=$payload;
            if(!$row['source_key'])continue;
            $linked[$row['id']]=$row['source_key'];
            foreach(self::links($payload) as $external)$groups[$row['provider']][$external]=true;
        }
        foreach($groups as $provider=>$keys)foreach(array_chunk(array_keys($keys),200) as $batch){
            $rows=$this->db->all('SELECT external_id,payload FROM delivery_orders WHERE company_id=? AND provider=? AND external_id IN ('.implode(',',array_fill(0,count($batch),'?')).')',[$company,$provider,...$batch]);
            foreach($rows as $row){
                $p=$this->cipher->open($company,'order:'.$provider.':'.$row['external_id'],$row['payload']);
                if(!empty($p['details']['superseded']))continue;
                $sources[$provider][$row['external_id']]=$p;
                $codes[$provider][$row['external_id']]=ShipmentProductCode::index($p);
            }
        }
        $productLines=[];$priceLinks=[];$pendingInvoices=[];$exportLines=[];
        $items=array_map(function($item)use($payloads,$sources,$linked,$codes,$includeInvoiceIdentity,$includeProductIdentity,$includePaymentDetails,$forExport,$includeShippingLabels,&$exportLines,&$productLines,&$priceLinks,&$pendingInvoices){
            $p=$payloads[$item['id']]??[];$numbers=[];$recipients=[];$productCodes=[];$taxOffices=[];$invoiceOrders=[];
            $billingEdited=in_array('invoiceAddress',$p['source']['locked']??[],true);
            $sourceKey=$linked[$item['id']]??null;
            $prices=$includePaymentDetails&&$sourceKey!==null?ShipmentPaymentAmounts::clean($p['marketplaceAmounts']??[]):[];
            $invoiceRow=['source_key'=>$sourceKey,'row_status'=>$item['rowStatus']??'','invoiced'=>$item['invoiced']??false,'cancelled'=>$item['cancelled']??false];
            $readInvoice=$item['provider']==='Pazarama'&&!PazaramaShipmentInvoice::keepRecordedAmount($invoiceRow,$p);
            if($readInvoice)$item['invoiceCents']=PazaramaShipmentInvoice::read($invoiceRow,$p);
            $findInvoice=$readInvoice&&$item['invoiceCents']===null&&($p['invoiceBasis']??null)!=='buyer_product';
            $packages=[];$shippingLabels=[];
            foreach($sourceKey!==null?self::links($p):[] as $key){
                $source=$sources[$item['provider']][$key]??[];
                if($source)$packages[]=$source;
                $productCodes+=($codes[$item['provider']][$key][$sourceKey]??[]);
                if(!$billingEdited){
                    $office=self::taxOffice($source['details']??[]);
                    if($office!=='')$taxOffices[$office]=true;
                }
                $number=trim($source['orderNumber']??'');if($number!=='')$numbers[$number]=true;
                $name=trim($source['details']['recipientName']??$source['recipientName']??'');if($name!=='')$recipients[$name]=true;
                if(($forExport||$includeInvoiceIdentity||($includePaymentDetails&&!$prices)||$findInvoice)&&$number!=='')foreach($source['details']['products']??[] as $line){
                    if(DeliveryFulfillment::key($source,$line)!==$sourceKey)continue;
                    if($forExport)$exportLines[$item['id']][]=$line;
                    if($includeShippingLabels){
                        $label=DeliveryShippingLabels::forProduct($source,$line);
                        $shippingLabels[]=$label+['carrier'=>trim((string)($source['details']['cargoCompany']??''))];
                    }
                    $identity=$source['provider']==='Pazarama'?$source['externalId']:$number;
                    if($includeInvoiceIdentity)$invoiceOrders[$identity]=$number;
                    if($includeProductIdentity)$productLines[$item['id']][]=[$item['provider'],$line];
                    if($findInvoice&&array_key_exists('invoiceCents',$line)&&($line['quantity']??null)===($p['quantity']??null)){
                        $item['invoiceCents']=$line['invoiceCents'];$findInvoice=false;
                    }
                    if(($includePaymentDetails&&!$prices)||$findInvoice)$priceLinks[$item['id']][]=[$item['provider'],$identity,(string)$line['lineId'],$p['quantity']??null];
                }
            }
            $item=array_replace($item,ShipmentDeliveryLocation::fields($item,$p,$packages));
            $recordedCode=$sourceKey===null?ShipmentProductCode::legacyCode($p,$item['provider'])
                :ShipmentProductCode::normalize($p['sellerStockCode']??null);
            if($recordedCode!=='')$productCodes[$recordedCode]=true;
            $skuNames=[];
            foreach(array_keys($productCodes) as $code)$skuNames[InventoryLiveSkuIdentity::normalize((string)$code)]=(string)$code;
            $item['productCode']=count($skuNames)===1?reset($skuNames):'';
            $item['taxOffice']=$billingEdited?'':(count($taxOffices)===1?(string)array_key_first($taxOffices):trim((string)($p['taxOffice']??'')));
            // The known package recipient is authoritative only when linked sources agree.
            if(trim($item['recipientName']??'')===''&&count($recipients)===1)$item['recipientName']=(string)array_key_first($recipients);
            $item['orderNumber']=$numbers?implode(', ',array_keys($numbers)):trim($p['orderNumber']??'');
            if($prices)$item['marketplaceAmounts']=$prices;
            if($shippingLabels)$item['shippingLabels']=DeliveryShippingLabels::merge($shippingLabels);
            if($findInvoice)$pendingInvoices[$item['id']]=true;
            if($includeInvoiceIdentity&&count($invoiceOrders)===1){
                $item['invoiceOrderIdentity']=(string)array_key_first($invoiceOrders);
                $item['orderNumber']=reset($invoiceOrders);
            }
            // Keep operational/editing data intact; label imported PTT identities by their actual source.
            if($item['provider']==='PTT AVM'&&isset($linked[$item['id']])&&!in_array('customerName',$p['source']['locked']??[],true)){
                foreach(self::links($p) as $key){
                    $source=$sources['PTT AVM'][$key]??[];
                    if($source&&!isset($source['manualCorrections']['buyerName'])&&trim($item['customerName']??'')===trim($source['details']['recipientName']??''))$item['customerNameSource']='recipient';
                }
            }
            $item['dataIssues']=[];
            if(trim($item['recipientName']??'')==='')$item['dataIssues'][]='Alıcı adı eksik';
            if($item['orderNumber']==='')$item['dataIssues'][]='Sipariş numarası doğrulanamadı';
            return $item;
        },$items);
        if($priceLinks){
            $amounts=(new ShipmentPaymentSnapshots($this->db))->read($company,$priceLinks);
            foreach($items as &$item){
                $match=$amounts[$item['id']]??[];
                if(isset($pendingInvoices[$item['id']]))$item['invoiceCents']=$match['netProductCents']??null;
                if($includePaymentDetails&&$match)$item['marketplaceAmounts']=$match;
            }
            unset($item);
        }
        if($forExport){
            $output=[];
            foreach($items as $item){
                $line=ShipmentExportLine::project($item,$exportLines[$item['id']]??[]);
                if($line!==null)$output[]=$line;
            }
            return $output;
        }
        if(!$productLines)return $items;
        $identities=ShipmentInvoiceProducts::identities($productLines);
        foreach($items as &$item)if(isset($identities[$item['id']]))$item['invoiceProductIdentity']=$identities[$item['id']];
        unset($item);
        return $items;
    }
    private function records(int $company,array $items):\Generator
    {
        foreach(array_chunk(array_column($items,'id'),100) as $ids){
            $rows=$this->db->all('SELECT id,provider,payload,source_key FROM shipment_entries WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$company,...$ids]);
            foreach($rows as $row)yield [$row,$this->cipher->open($company,'shipment:'.$row['id'],$row['payload'])];
        }
    }
    private static function taxOffice(array $contact):string
    {
        $office=trim((string)($contact['taxOffice']??''));
        if($office!==''||!preg_match('/(?:Vergi\\s*dairesi|V\\s*\\.\\s*D\\s*\\.?)\\s*[:：]\\s*([^\\r\\n]+)/iu',(string)($contact['invoiceAddress']??''),$match))return $office;
        return trim($match[1]);
    }
    private static function links(array $p):array{return array_values(array_unique(array_filter([$p['sourceDeliveryId']??null,...($p['sourceDeliveryIds']??[])])));}
}
