<?php
declare(strict_types=1);
namespace Omnigo;

final class ReconciliationDownstream
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function compare(int $company,string $provider,array $reference,callable $issue): void
    {
        $name=ReconciliationReference::PROVIDERS[$provider];$deliveries=[];
        if($reference['deliveries']??[])foreach($this->db->all('SELECT id,external_id,active,updated FROM delivery_orders WHERE company_id=? AND provider=?',[$company,$name]) as $row)$deliveries[ReconciliationReference::key($row['external_id'])][]=$row;
        foreach($reference['deliveries']??[] as $key=>$expected){
            $rows=$deliveries[$key]??[];$context=['area'=>'deliveries','reference'=>$expected['reference']];
            // Cancelled/inactive packages intentionally do not belong in delivery documents.
            if(!$rows&&$expected['active'])$issue('missing',$context);
            elseif(count($rows)>1)$issue('duplicate',[...$context,'count'=>count($rows)]);
            foreach($rows as $row)if(strtotime($row['updated'])<strtotime($reference['capturedAt'])&&((int)$row['active']===1)!==$expected['active'])$issue('status',[...$context,'recordId'=>(int)$row['id']]);
        }
        if(empty($reference['shipments']))return;
        $activation=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='shipment_auto_since'",[$company]);
        if(!$activation)return;$since=(int)json_decode($activation['payload'],true,8,JSON_THROW_ON_ERROR)['since'];
        $entries=[];foreach($this->db->all('SELECT id,source_key,row_status,invoiced,cancelled,payload,removed_at,updated FROM shipment_entries WHERE company_id=? AND provider=? AND source_key IS NOT NULL',[$company,$name]) as $row)$entries[$row['source_key']][]=$row;
        foreach($reference['shipments'] as $key=>$expected){
            $date=strtotime($expected['orderedAt']);
            if(!in_array($expected['city'],CustomerCities::read($this->db,$company,true),true)||$date<$since||$date>time()+300||$expected['cancelled'])continue;
            $rows=$entries[$key]??[];$context=['area'=>'shipments','reference'=>$expected['reference']];
            if(!$rows){$issue('missing',$context);continue;}
            if(count($rows)>1)$issue('duplicate',[...$context,'count'=>count($rows)]);
            foreach($rows as $row){
                if($row['removed_at']!==null||strtotime($row['updated'])>strtotime($reference['capturedAt']))continue;
                $value=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
                foreach(['invoiceCents','quantity'] as $field){
                    if(in_array($field,$value['source']['locked']??[],true))continue;
                    if($provider==='pazarama'&&$field==='invoiceCents'){
                        if(($expected['invoiceBasis']??null)!=='buyer_product'||PazaramaShipmentInvoice::protectedRow($row,$value))continue;
                        $actual=PazaramaShipmentInvoice::read($row,$value);
                        if($actual!==$expected[$field])$issue('amount',[...$context,'recordId'=>(int)$row['id'],'field'=>$field,'expectedValue'=>$expected[$field],'actualValue'=>$actual]);
                        continue;
                    }
                    if((int)($value[$field]??0)!==$expected[$field])$issue('amount',[...$context,'recordId'=>(int)$row['id'],'field'=>$field,'expectedValue'=>$expected[$field],'actualValue'=>(int)($value[$field]??0)]);
                }
            }
        }
    }
}
