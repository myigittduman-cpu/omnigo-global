<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only projection of current shipment rows, including historical/manual entries. */
final class DeliveredShipmentQueue
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    private static function scope(int $company,string $city,string $provider,bool $visibleOnly): array
    {
        $sql="company_id=? AND removed_at IS NULL AND row_status IN ('delivered','completed')";$params=[$company];
        if($visibleOnly)$sql.=' AND '.MarketplaceVisibility::sql();
        if($city!=='all'){$sql.=' AND city=?';$params[]=$city;}
        if($provider!=='all'){$sql.=' AND provider=?';$params[]=$provider;}
        return [$sql,$params];
    }
    public function count(int $company,string $provider,bool $visibleOnly,array $filterValues=[]): int
    {
        [$sql,$params]=self::scope($company,'all',$provider,$visibleOnly);
        $filters=new DeliveryFilters($filterValues);$cursor=0;$total=0;
        do{
            $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$sql.' AND id>? ORDER BY id LIMIT 250',[...$params,$cursor]);
            foreach($rows as $row){$cursor=(int)$row['id'];$item=$this->item($company,$row);if($item['products'][0]['quantity']>0&&$filters->matches($item,false))$total++;}
        }while(count($rows)===250);
        return $total;
    }
    public function listing(int $company,string $city,string $provider,int $page,int $limit,bool $visibleOnly,array $filterValues=[]): array
    {
        return $this->filtered($company,$city,$provider,$page,$limit,$visibleOnly,$filterValues);
    }
    private function filtered(int $company,string $city,string $provider,int $page,int $limit,bool $visibleOnly,array $filterValues): array
    {
        [$sql,$params]=self::scope($company,$city,$provider,$visibleOnly);$filters=new DeliveryFilters($filterValues);$cursor=null;$matched=0;$items=[];
        do{
            $after=$cursor?' AND (ordered_at<? OR (ordered_at=? AND id<?))':'';$values=$cursor?[...$params,$cursor['ordered_at'],$cursor['ordered_at'],$cursor['id']]:$params;
            $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$sql.$after.' ORDER BY ordered_at DESC,id DESC LIMIT 250',$values);
            foreach($rows as $row){$item=$this->item($company,$row);if($item['products'][0]['quantity']<1||!$filters->matches($item))continue;if($matched++<$page*$limit)continue;$items[]=$item;if(count($items)>$limit)break 2;}
            $cursor=$rows?end($rows):null;
        }while(count($rows)===250);
        return ['items'=>array_slice($items,0,$limit),'hasMore'=>count($items)>$limit];
    }
    private function item(int $company,array $row): array
    {
        $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
        // Shipment rows have their own identity. Never invent a marketplace order number.
        return [
            'id'=>'shipment:'.$row['id'],'shipmentId'=>(int)$row['id'],'version'=>(int)$row['version'],
            'orderNumber'=>'','provider'=>$row['provider'],'city'=>$row['city'],'createdAt'=>$payload['orderedAt']??'',
            'buyerName'=>$payload['customerName']??'','recipientName'=>$payload['recipientName']??'',
            'phone'=>$payload['phone']??'','address'=>$payload['deliveryAddress']??'',
            'products'=>[['title'=>$payload['productName']??'','quantity'=>$payload['marketplaceReturn']['remainingQuantity']??$payload['quantity']??null,'sku'=>'']],
            'active'=>false,'issues'=>[],'queue'=>'delivered','status'=>'Teslim edildi','updated'=>$row['updated'],
            'fulfillmentChecks'=>[['id'=>(int)$row['id'],'version'=>(int)$row['version'],'provider'=>$row['provider'],'month'=>$row['month'],'city'=>$row['city'],'rowStatus'=>ShipmentStatus::resolve($row),'customerName'=>$payload['customerName']??'','productName'=>$payload['productName']??'',...ShipmentFulfillment::presentation($row,$payload)]],
        ];
    }
}
