<?php
declare(strict_types=1);
namespace Omnigo;

/** Terminal marketplace evidence outranks documents; an invoice colour requires an uploaded invoice. */
final class ShipmentColorPolicy
{
    public static function status(array $row,array $payload):string
    {
        $source=ShipmentSourceStatus::value($payload);
        if(in_array($source,['cancelled','undelivered'],true))return 'cancelled';
        if(in_array($source,['delivered','completed'],true))return 'completed';
        $linked=!empty($row['source_key']);$override=!empty($payload['manualStatusOverride']['enabled']);
        $status=$linked&&!$override?(ShipmentSourceStatus::rowStatus($payload)??ShipmentStatus::resolve($row)):ShipmentStatus::resolve($row);
        if($linked&&in_array($status,['completed','delivered'],true))$status=ShipmentSourceStatus::rowStatus($payload)??'';
        if($status==='cancelled')return 'cancelled';
        if(!$linked&&in_array($status,['completed','delivered'],true))return 'completed';
        if(!empty($payload['documents']['invoice']))return 'invoiced';
        if($status==='invoiced')$status=ShipmentSourceStatus::rowStatus($payload)??'';
        return in_array($status,['review','invoiced','delivered'],true)?'':$status;
    }
    public static function completedIntake(array $payload):bool
    {
        return ShipmentSourceStatus::isDelivered($payload)&&trim((string)($payload['orderNumber']??''))!==''&&trim((string)($payload['productName']??''))!==''&&is_int($payload['quantity']??null)&&$payload['quantity']>0;
    }
}
