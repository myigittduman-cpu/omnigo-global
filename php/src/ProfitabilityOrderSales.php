<?php
declare(strict_types=1);
namespace Omnigo;

/** Load canonical local sales for a bounded set of orders. No marketplace API is called. */
final class ProfitabilityOrderSales
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null) {}

    public function read(int $company,array $orders): array
    {
        $selected=[];
        foreach($orders as $order)$selected[$order['provider']][]=$order['external_id'];
        $result=[];
        foreach($selected as $provider=>$ids){
            $projection=$this->recent($company,$provider,$ids);
            if(in_array($provider,['koctas','n11','idefix','pazarama'],true)){
                $financial=$this->financial($company,$provider,$ids);
                if($provider==='koctas')$projection=$financial;
                else foreach($financial as $id=>$order){
                    // Financial scans can finish after a newer operational cancellation was seen.
                    $projection[$id]=self::combine($projection[$id]??null,$order);
                }
            }
            if($provider==='hepsiburada'){
                $reader=new ProfitabilityShipmentPricing($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
                $projection=$reader->hepsiburada($company,$projection);
            }
            $result[$provider]=$projection;
        }
        return $result;
    }

    private function recent(int $company,string $provider,array $ids): array
    {
        if($provider==='koctas'||!RecentOrderSchema::available($this->db))return [];
        $in=implode(',',array_fill(0,count($ids),'?'));
        $providers=$provider==='hepsiburada'?[$provider,HepsiburadaSalesOrders::PROVIDER]:[$provider];
        $params=implode(',',array_fill(0,count($providers),'?'));
        $rows=$this->db->all("SELECT provider,external_id,payload FROM recent_orders
            WHERE company_id=? AND provider IN ($params) AND external_id IN ($in)",[$company,...$providers,...$ids]);
        $result=[];$finance=[];
        foreach($rows as $row){
            $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if($row['provider']===HepsiburadaSalesOrders::PROVIDER)$finance[$row['external_id']]=$value;
            else $result[$row['external_id']]=$value;
        }
        foreach($finance as $id=>$order)$result[$id]=self::combine($result[$id]??null,$order);
        return $result;
    }

    private function financial(int $company,string $provider,array $ids): array
    {
        $json="JSON_EXTRACT(payload,'$.externalId')";
        if($this->db->mysql())$json='JSON_UNQUOTE('.$json.')';
        $in=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->db->all("SELECT payload FROM records WHERE company_id=? AND kind=? AND $json IN ($in)",
            [$company,'order_'.$provider,...$ids]);
        $orders=[];
        foreach($rows as $row){
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            $id=(string)$order['externalId'];
            // Duplicate financial rows with different values have no authoritative winner.
            $orders[$id]=array_key_exists($id,$orders)&&$orders[$id]!==$order?null:$order;
        }
        return $orders;
    }

    private static function combine(?array $live,?array $financial): ?array
    {
        if($live===null)return $financial;
        if($financial===null)return null;
        if(empty($live['orders']))return null;
        if(($live['grossCents']??null)!==($financial['grossCents']??null)){
            // A new package may arrive while the monthly snapshot still represents an earlier order shape.
            return empty($financial['returnCents'])?$live:null;
        }
        if(($financial['cancelCents']??0)>=($live['cancelCents']??0)&&($financial['returnCents']??0)>=($live['returnCents']??0))return self::retainPricing($financial,$live);
        if(($live['cancelCents']??0)>=($financial['cancelCents']??0)&&($live['returnCents']??0)>=($financial['returnCents']??0))return $live;
        // Incomparable cancellation/refund versions must not be added together.
        return null;
    }

    /** Historical finance snapshots may predate optional line pricing in the operational projection. */
    private static function retainPricing(array $financial,array $operational): array
    {
        if(($financial['externalId']??null)!==($operational['externalId']??null)
            ||strtotime($financial['createdAt']??'')!==strtotime($operational['createdAt']??''))return $financial;
        $candidates=[];
        foreach($operational['products']??[] as $line){
            $id=(string)($line['lineId']??'');
            if($id!=='')$candidates[$id]=array_key_exists($id,$candidates)?null:$line;
        }
        foreach($financial['products']??[] as $index=>$line){
            $other=$candidates[(string)($line['lineId']??'')]??null;
            if(isset($line['pricing'])||!isset($other['pricing'])||($line['sku']??null)!==($other['sku']??null)
                ||$line['quantity']!==$other['quantity']||$line['grossCents']!==$other['grossCents'])continue;
            $financial['products'][$index]['pricing']=$other['pricing'];
        }
        return $financial;
    }
}
