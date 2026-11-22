<?php
declare(strict_types=1);
namespace Omnigo;

/** Shared status projection; the retired manual verification flags are ignored. */
final class ShipmentFulfillment
{
    public static function physical(string $status): string
    {
        return match($status){'shipped'=>'shipped','delivered','completed'=>'delivered','cancelled'=>'cancelled',default=>'pending'};
    }
    public static function source(array $payload): string{return ShipmentSourceStatus::value($payload);}
    public static function fullyReturned(array $return): bool
    {
        $count=(int)($return['returnedQuantity']??0);
        if($count<1)return false;
        $quantity=(int)($return['quantity']??0);
        return in_array($return['partial']??null,[false,0,'0','false'],true)
            ||($quantity>0&&$count>=$quantity)
            ||(isset($return['remainingQuantity'])&&is_numeric($return['remainingQuantity'])&&(int)$return['remainingQuantity']===0);
    }
    public static function presentation(array $row,array $payload): array
    {
        $source=self::source($payload);$override=!empty($payload['manualStatusOverride']['enabled'])&&!in_array($source,['cancelled','undelivered','delivered','completed'],true);$automatic=!empty($row['source_key'])&&!$override;
        $status=ShipmentColorPolicy::status($row,$payload);
        if($status==='review')$status='';
        if($status==='delivered')$status='completed';
        $actual=self::physical($status);
        $returned=$payload['marketplaceReturn']??null;
        $fullReturn=self::fullyReturned($returned??[]);
        $returnLabel=($returned['returnedQuantity']??0)>0?($fullReturn?'İade onaylandı':'Kısmi iade onaylandı'):null;
        return ['marketplaceReturn'=>$returned,'marketplaceStatus'=>$source,'marketplaceStatusText'=>$returnLabel??($source===''?'Bilgi yok':self::label($source)),
            'marketplaceDelivered'=>ShipmentSourceStatus::isDelivered($payload),'statusSource'=>$automatic?'marketplace':'manual','manualStatusOverride'=>$override,
            'fulfillmentStatus'=>$fullReturn?'returned':$actual,'fulfillmentStatusText'=>$returnLabel??self::label($status),'statusMismatch'=>false,
            'fulfillmentNeedsReview'=>false,'displayRowStatus'=>$status,
            'fulfillmentConfirmedAt'=>null,'fulfillmentConfirmedBy'=>null,'cancellationNeedsReview'=>false];
    }
    public static function label(string $status): string
    {
        return match($status){'pending',''=>'Sevk bekliyor','shipped'=>'Sevk edildi','delivered','completed'=>'Sipariş Tamamlandı','cancelled'=>'İptal edildi','invoiced'=>'Faturası kesildi','review'=>'Pazaryerinde kontrol sürecinde','undelivered'=>'Teslim edilemedi',default=>'Bilgi yok'};
    }
    /** Compatibility for manual rows: refresh dependent documents without confirming source status. */
    public static function confirm(Database $db,DeliveryCipher $cipher,array $user,int $company,int $id,bool $persistOverride=true): array
    {
        $row=$db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?',[$company,$id]);
        $payload=$cipher->open($company,'shipment:'.$id,$row['payload']);
        if($persistOverride){
            $payload['manualStatusOverride']=['enabled'=>true,'status'=>ShipmentStatus::resolve($row),'updatedAt'=>gmdate('c'),'userId'=>(int)$user['id']];
            $db->run('UPDATE shipment_entries SET payload=? WHERE company_id=? AND id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$company,$id]);
        }
        $links=array_values(array_unique(array_filter([...($payload['sourceDeliveryIds']??[]),$payload['sourceDeliveryId']??null])));
        foreach($links as $external)$db->run('UPDATE delivery_orders SET version=version+1,document_id=NULL,updated=? WHERE company_id=? AND provider=? AND external_id=?',[gmdate('c'),$company,$row['provider'],$external]);
        return self::presentation($row,$payload);
    }
}
