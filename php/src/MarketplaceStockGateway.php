<?php
declare(strict_types=1);
namespace Omnigo;

interface MarketplaceStockGateway
{
    public function read(array $configuration,string $sku,int $deadline):array;
    public function submit(array $configuration,array $listing,int $quantity,int $deadline):string;
    public function status(array $configuration,string $receipt,int $deadline):string;
}
