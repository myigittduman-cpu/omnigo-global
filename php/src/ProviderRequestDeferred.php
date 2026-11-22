<?php
declare(strict_types=1);
namespace Omnigo;

final class ProviderRequestDeferred extends \RuntimeException
{
    public function __construct(public readonly int $retryAt)
    {
        parent::__construct('Pazaryerinin sıradaki istek aralığı bekleniyor.');
    }
}
