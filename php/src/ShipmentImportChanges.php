<?php
declare(strict_types=1);
namespace Omnigo;

/** Collects only confirmed row transitions from this marketplace import. */
final class ShipmentImportChanges
{
    private bool $added=false;
    private bool $cancelled=false;

    public function added(string $status): void
    {
        if(!in_array($status,['cancelled','completed'],true))$this->added=true;
    }

    public function statusChanged(string $status,array $incoming): void
    {
        if($status==='cancelled'&&ShipmentSourceStatus::value($incoming)==='cancelled')$this->cancelled=true;
    }

    public function events(): array
    {
        return [...($this->added?[CustomerOrderNotifications::SHIPMENT_ADDED]:[]),
            ...($this->cancelled?[CustomerOrderNotifications::SHIPMENT_CANCELLED]:[])];
    }
}
