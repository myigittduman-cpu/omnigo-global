<?php
declare(strict_types=1);
namespace Omnigo;

interface ScheduledReadAdapter extends ReadOnlyAdapter
{
    public function nextDelay(): int;
}
