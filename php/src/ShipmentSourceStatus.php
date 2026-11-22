<?php
declare(strict_types=1);
namespace Omnigo;

/** Marketplace status is authoritative; invoice and contact edits remain independent. */
final class ShipmentSourceStatus
{
    public static function value(array $payload): string
    {
        $cancel=$payload['marketplaceCancellation']??[];
        if(($cancel['quantity']??0)>0&&($cancel['cancelledQuantity']??0)===$cancel['quantity'])return 'cancelled';
        // Historic imports may retain a derived shipped label after the source reports delivery.
        if(($payload['marketplaceShipping']??'')==='delivered')return 'delivered';
        $status=$payload['marketplaceRowStatus']??$payload['marketplaceShipping']??'';
        return in_array($status,['pending','cancelled','shipped','delivered','invoiced','completed','undelivered','review'],true)?$status:'';
    }
    public static function isDelivered(array $payload): bool{return in_array(self::value($payload),['delivered','completed'],true);}
    public static function rowStatus(array $payload): ?string
    {
        return match(self::value($payload)){'delivered','completed'=>'completed','shipped'=>'shipped','cancelled','undelivered'=>'cancelled','invoiced'=>'invoiced','pending','review'=>'',default=>null};
    }
    public static function next(array $row,array $stored,array $incoming,bool $manual): ?string
    {
        if(!empty($row['removed_at']))return null;
        if(!empty($stored['manualStatusOverride']['enabled'])&&!in_array(self::value($incoming),['cancelled','undelivered','delivered','completed'],true))return null;
        $source=self::rowStatus($incoming);if($source===null)return null;
        $next=ShipmentColorPolicy::status([...$row,'source_key'=>$row['source_key']??'incoming-source'],[...$stored,...$incoming]);
        return $next!==null&&$next!==ShipmentStatus::resolve($row)?$next:null;
    }
}
