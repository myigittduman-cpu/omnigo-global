<?php
declare(strict_types=1);
namespace Omnigo;

/** Recovery cadence is independent of the successful-request cooldown. */
interface TransientRetryReadAdapter extends ReadOnlyAdapter
{
    public function transientRetryDelay(): int;
}
