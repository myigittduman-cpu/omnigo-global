<?php
declare(strict_types=1);
namespace Omnigo;

/** Only a confirmed older order snapshot may be isolated from a valid API page. */
final class OrderSnapshotConflict extends SyncFailure
{
    public function __construct(){parent::__construct(409,60);}
}
