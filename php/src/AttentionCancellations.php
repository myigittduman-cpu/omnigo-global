<?php
declare(strict_types=1);
namespace Omnigo;

/** Only explicit marketplace cancellation requests; never changes the order. */
final class AttentionCancellations
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function pending(int $company):array
    {
        $items=[];
        foreach($this->records($company) as [$row,$order,$lines]){
            $items[]=['key'=>hash('sha256','returns:cancellation:'.$row['id']),'fingerprint'=>hash('sha256',json_encode($lines,JSON_THROW_ON_ERROR)),'type'=>'returns','title'=>'İşlem bekleyen iptal talebi',
                'detail'=>($order['buyerName']??$order['recipientName']??'Sipariş').' · Pazarama · Sipariş '.($order['orderNumber']??$row['external_id']),
                'source'=>['view'=>'orders','kind'=>'cancellation','id'=>(int)$row['id'],'lines'=>$lines],'priority'=>3,'due'=>substr($order['createdAt']??$row['updated'],0,10)];
        }
        return $items;
    }

    public function requests(int $company): array
    {
        $items=[];
        foreach($this->records($company) as [$row,$order,$lines]){
            $products=array_filter($order['details']['products']??[],static fn(array $line):bool=>in_array((string)($line['lineId']??''),$lines,true));
            $titles=array_filter(array_map(static fn(array $line):string=>ReturnRequestMapper::text($line['title']??$line['name']??'',160),$products));
            $number=(string)($order['orderNumber']??$row['external_id']);
            $items[]=['id'=>'cancel:'.$row['id'],'orderNumber'=>$number,'lineId'=>implode(', ',$lines),
                'provider'=>'Pazarama','providerKey'=>'pazarama','type'=>'cancellation','group'=>'pending','status'=>'18',
                'statusLabel'=>'İptal talebi bekliyor','createdAt'=>null,'quantity'=>null,'amountCents'=>null,
                'checkedAt'=>$row['updated'],'recordType'=>'order_line','customerName'=>$order['buyerName']??$order['recipientName']??'',
                'product'=>implode(' · ',array_unique($titles)),'skus'=>ReturnRequestMapper::skus($products),'reason'=>'','partial'=>false];
        }
        return $items;
    }

    private function records(int $company): iterable
    {
        $repo=new DeliveryRepository($this->db,$this->cipher);
        $after=0;
        do {
            $rows=$this->db->all("SELECT * FROM delivery_orders WHERE company_id=? AND provider='Pazarama' AND active=1 AND id>? ORDER BY id LIMIT 200",[$company,$after]);
            foreach($rows as $row){
                $after=(int)$row['id'];$order=$repo->decode($row);
                $lines=CancellationRequestEvents::lines($order);
                if($lines&&substr($order['createdAt']??'',0,10)>=AttentionSources::SINCE)yield [$row,$order,$lines];
            }
        } while(count($rows)===200);
    }
    public function resolved(int $company,array $source):bool
    {
        $row=$this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND id=?',[$company,$source['id']]);if(!$row)return false;
        $order=(new DeliveryRepository($this->db,$this->cipher))->decode($row);$statuses=[];
        foreach($order['details']['products']??[] as $line)$statuses[(string)($line['lineId']??'')]=(string)($line['status']??'');
        foreach($source['lines'] as $id)if(!in_array($statuses[$id]??'',array_map('strval',[3,6,12,13,5,16,19,11,14,7,8,9,10]),true))return false;
        return !empty($source['lines']);
    }
}
