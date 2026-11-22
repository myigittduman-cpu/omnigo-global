<?php
declare(strict_types=1);
namespace Omnigo;

/** Build the current shipment view from the source status and shipment row. */
final class ShipmentDispatchStatus
{
    public function __construct(private DeliveryCipher $cipher){}

    public function read(int $company,array $rows): array
    {
        return array_map(function(array $row)use($company):array{
            $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
            return [$row,$payload,ShipmentFulfillment::presentation($row,$payload)];
        },$rows);
    }
}
