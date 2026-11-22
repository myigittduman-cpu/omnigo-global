<?php
declare(strict_types=1);
namespace Omnigo;

final class InboxFailure extends \RuntimeException
{
    public function __construct(public int $status, public int $retryAfter=0, public bool $uncertain=false,
        public readonly ?array $answeredQuestion=null)
    { parent::__construct('Mesaj işlemi tamamlanamadı.'); }
}
