<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceStockFailure extends \RuntimeException
{
    public function __construct(public readonly bool $uncertain=false,public readonly bool $retryable=false,public readonly int $retryAfter=30)
    {parent::__construct('Pazaryeri stok işlemi şu anda doğrulanamadı.');}
}
