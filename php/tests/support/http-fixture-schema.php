<?php
declare(strict_types=1);

/** Complete local HTTP fixture schema; never loads production configuration. */
function installHttpFixtureSchema(\Omnigo\Database $db):void
{
    \Omnigo\InventorySchema::install($db);
    \Omnigo\SupportSchema::migrate($db);
    \Omnigo\NotificationSchema::migrate($db);
    \Omnigo\DeliverySchema::install($db);
    \Omnigo\ShipmentSchema::install($db);
    \Omnigo\ClientSessionOperator::install($db);
    \Omnigo\AccountSettingsSchema::install($db);
    \Omnigo\AdminMfaSchema::install($db);
    \Omnigo\OperationSchema::install($db);
    \Omnigo\MarketplaceGate::install($db);
}
