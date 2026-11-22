<?php
declare(strict_types=1);
namespace Omnigo;

final class ShipmentCustomerVisibility
{
    public const SQL="cancelled=0 AND COALESCE(row_status,'')<>'cancelled'";

    public static function visible(array $row,array $payload): bool
    {
        $status=[
            'cancelled'=>$row['cancelled']??false,
            'rowStatus'=>ShipmentStatus::resolve($row),
            'displayRowStatus'=>ShipmentColorPolicy::status($row,$payload),
        ];
        return OperationalCancellationMark::shipmentMark($status,$payload['marketplaceCancellation']??[])!=='cancelled';
    }

    public static function readable(array $row,array $payload,bool $hideCancellations,bool $hideReturns): bool
    {
        if($hideCancellations&&!self::visible($row,$payload))return false;
        $returned=$payload['marketplaceReturn']??null;
        return !$hideReturns||($returned['returnedQuantity']??0)<=0||!empty($returned['partial']);
    }

    public static function requireVisible(array $user,array $row,array $payload): void
    {
        if($user['role']==='client'&&!self::visible($row,$payload))Http::fail(404,'Sevkiyat kaydı bulunamadı.');
    }

    public static function requireStatus(array $user,mixed $value): void
    {
        if($user['role']==='client'&&$value==='cancelled')Http::fail(403,'Bu durumu değiştirme yetkiniz yok.');
    }

    public static function filters(array $filters): array
    {
        $filters['cancelled']='';
        if($filters['rowStatus']==='cancelled')$filters['rowStatus']='';
        return $filters;
    }
}
