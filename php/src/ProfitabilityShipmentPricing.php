<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads the originally imported marketplace amounts by the exact company and order-line source key. */
final class ProfitabilityShipmentPricing
{
    public function __construct(private Database $db, private DeliveryCipher $cipher) {}

    public function hepsiburada(int $company, array $orders): array
    {
        $references=[];
        foreach($orders as $key=>$order){
            if(!is_array($order)||(string)($order['externalId']??'')!==(string)$key)continue;
            foreach($order['products']??[] as $index=>$line){
                $id=$line['lineId']??null;
                if((!is_string($id)&&!is_int($id))||(string)$id==='')continue;
                $keyParts=json_encode([(string)$key,(string)$id],JSON_THROW_ON_ERROR);
                $source=hash('sha256','Hepsiburada:'.hash('sha256',$keyParts));
                // Conflicting duplicates have no unique line mapping.
                $references[$source]=array_key_exists($source,$references)?null:[$key,$index];
            }
        }
        if(!$references)return $orders;
        $exists=$this->db->mysql()?$this->db->one("SHOW TABLES LIKE 'shipment_entries'"):
            $this->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='shipment_entries'");
        if(!$exists)return $orders;
        foreach(array_chunk(array_keys(array_filter($references)),150) as $chunk){
            $placeholders=implode(',',array_fill(0,count($chunk),'?'));
            $rows=$this->db->all("SELECT id,source_key,payload FROM shipment_entries WHERE company_id=? AND provider='Hepsiburada'
                AND removed_at IS NULL AND source_key IN ($placeholders)",[$company,...$chunk]);
            foreach($rows as $row){
                [$key,$index]=$references[$row['source_key']];
                try{
                    $stored=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
                }catch(\JsonException|\RuntimeException $error){
                    error_log('Omnigo kârlılık: sevkiyat fiyat kaydı doğrulanamadı.');
                    continue;
                }
                $line=$orders[$key]['products'][$index];
                $amounts=$stored['marketplaceAmounts']??null;
                if(!is_array($amounts))continue;
                $amounts=ShipmentPaymentAmounts::clean($amounts);
                if(($stored['source']['kind']??'')!=='marketplace'||($stored['source']['provider']??null)!=='Hepsiburada'
                    ||(string)($stored['orderNumber']??'')!==(string)$key||($stored['quantity']??null)!==$line['quantity']
                    ||($amounts['productCents']??null)!==$line['grossCents']||($stored['invoiceCents']??null)!==$line['grossCents']
                    ||in_array('marketplaceAmounts',$stored['source']['locked']??[],true))continue;
                $orders[$key]['products'][$index]['orderPricing']=ProfitabilityMarketplacePricing::hbMerchandise(
                    $amounts['merchantCents']??null,$line['grossCents'],$amounts['sellerDiscountCents']??null,$amounts['platformDiscountCents']??null);
            }
        }
        return $orders;
    }
}
